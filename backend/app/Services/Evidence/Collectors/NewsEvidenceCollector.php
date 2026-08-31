<?php

declare(strict_types=1);

namespace App\Services\Evidence\Collectors;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Evidence\EvidenceCandidate;
use App\Services\Evidence\EvidenceCollector;
use App\Services\Evidence\EvidenceWindow;
use App\Services\Evidence\GdeltNewsClient;

/**
 * NEWS evidence — the ONLY collector that makes an external request.
 *
 * It searches GDELT for articles published INSIDE the investigation window whose
 * TITLE actually names this token (exact name, or symbol as a standalone token).
 * Articles that only collide on a common ticker are dropped.
 *
 * A near-in-time article is recorded as a neutral fact:
 *   "Article '…' was published 12 minutes before the observed pump peak."
 * It is NEVER recorded as a cause. Publication proximity is not causation.
 */
class NewsEvidenceCollector implements EvidenceCollector
{
    /** Names/symbols too generic to build a safe news query from. */
    private const GENERIC_TERMS = [
        'meme', 'memecoin', 'coin', 'token', 'crypto', 'cryptocurrency', 'the', 'moon',
        'pump', 'dump', 'money', 'cash', 'usd', 'btc', 'eth', 'sol', 'baby', 'mini',
        'inu', 'shiba', 'doge', 'pepe', 'wojak', 'chad', 'gm', 'wagmi', 'hodl', 'ai',
    ];

    public function __construct(private readonly GdeltNewsClient $client) {}

    public function name(): string
    {
        return 'news';
    }

    public function isExternal(): bool
    {
        return true;
    }

    /** Reset the shared per-run GDELT request budget. */
    public function resetBudget(): void
    {
        $this->client->resetBudget();
    }

    public function lastProviderCallFailed(): bool
    {
        return $this->client->lastCallFailed();
    }

    /**
     * @return list<EvidenceCandidate>
     */
    public function collect(PumpEvent $event, Token $token, EvidenceWindow $window): array
    {
        if (! (bool) config('evidence.news.enabled', true)) {
            return [];
        }
        if ((string) config('evidence.news.provider', 'gdelt') !== 'gdelt') {
            return [];
        }

        $query = $this->buildQuery($token);
        if ($query === null) {
            return [];
        }

        $maxResults = (int) config('evidence.news.max_results_per_event', 10);
        $articles = $this->client->search(
            $query,
            $window->investigationStart,
            $window->investigationEnd,
            $maxResults,
        );

        $name = trim((string) $token->name);
        $symbol = trim((string) $token->symbol);
        $trusted = (array) config('evidence.news.trusted_domains', []);
        $minSymbol = (int) config('evidence.news.minimum_symbol_length', 4);

        $windowMinutes = max(
            1,
            (int) round(abs($window->investigationStart->diffInMinutes($window->investigationEnd, false))),
        );

        $out = [];
        foreach ($articles as $article) {
            if (count($out) >= $maxResults) {
                break;
            }
            if (! $window->contains($article->seenAt)) {
                continue;
            }

            $match = $this->titleMatch($article->title, $name, $symbol, $minSymbol);
            if ($match === null) {
                continue; // ticker-collision guard — no confirmed entity match
            }

            $isTrusted = $this->domainTrusted($article->domain, $trusted);
            $publishedAt = $article->seenAt;
            $timing = $publishedAt !== null
                ? $window->relativeToPeak($publishedAt)
                : 'at an unknown time relative to the observed pump peak';

            $gapMinutes = $publishedAt !== null
                ? (int) round(abs($publishedAt->diffInMinutes($window->eventPeak, false)))
                : $windowMinutes;
            $proximityBonus = max(0, 20 - (int) round($gapMinutes * 20 / $windowMinutes));

            $confidence = match (true) {
                $match === 'name' && $isTrusted && $gapMinutes <= 60 => Evidence::CONFIDENCE_HIGH,
                $match === 'name' => Evidence::CONFIDENCE_MEDIUM,
                $isTrusted => Evidence::CONFIDENCE_MEDIUM,
                default => Evidence::CONFIDENCE_LOW,
            };

            $relevance = 25
                + ($match === 'name' ? 35 : 15)
                + ($isTrusted ? 15 : 0)
                + $proximityBonus;

            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_NEWS,
                source: 'gdelt',
                sourceUrl: $article->url,
                title: mb_substr($article->title, 0, 500),
                observedAt: $publishedAt,
                publishedAt: $publishedAt,
                relevanceScore: $relevance,
                confidence: $confidence,
                summary: sprintf(
                    '%s article "%s" (%s) was published %s. Recorded as a temporally proximate publication only — not a causal claim.',
                    $isTrusted ? 'Crypto-news' : 'News',
                    $article->title,
                    $article->domain !== '' ? $article->domain : 'unknown source',
                    $timing,
                ),
                rawReference: $article->domain !== '' ? 'domain:'.$article->domain : null,
            );
        }

        return $out;
    }

    private function buildQuery(Token $token): ?string
    {
        $name = trim((string) $token->name);
        $symbol = trim((string) $token->symbol);
        $minSymbol = (int) config('evidence.news.minimum_symbol_length', 4);

        if ($name !== '' && mb_strlen($name) >= 4 && ! $this->isGeneric($name)) {
            return '"'.$this->sanitize($name).'" (crypto OR token OR memecoin OR coin)';
        }

        if ($symbol !== '' && mb_strlen($symbol) >= $minSymbol && ! $this->isGeneric($symbol)) {
            return '"'.$this->sanitize($symbol).'" (crypto OR token OR memecoin)';
        }

        return null;
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/["()]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function isGeneric(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, self::GENERIC_TERMS, true);
    }

    /**
     * @return 'name'|'symbol'|null
     */
    private function titleMatch(string $title, string $name, string $symbol, int $minSymbol): ?string
    {
        $haystack = ' '.mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title)).' ';

        if ($name !== '' && mb_strlen($name) >= 4) {
            $needle = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
            if ($needle !== '' && str_contains($haystack, ' '.$needle.' ')) {
                return 'name';
            }
        }

        if ($symbol !== '' && mb_strlen($symbol) >= $minSymbol) {
            $needle = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($symbol));
            if ($needle !== '' && str_contains($haystack, ' '.$needle.' ')) {
                return 'symbol';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $trusted
     */
    private function domainTrusted(string $domain, array $trusted): bool
    {
        if ($domain === '') {
            return false;
        }

        foreach ($trusted as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if ($candidate !== '' && ($domain === $candidate || str_ends_with($domain, '.'.$candidate))) {
                return true;
            }
        }

        return false;
    }
}
