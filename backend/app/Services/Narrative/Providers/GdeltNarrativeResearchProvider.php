<?php

declare(strict_types=1);

namespace App\Services\Narrative\Providers;

use App\Models\TokenNarrativeSource;
use App\Services\Narrative\NarrativeResearchContext;
use App\Services\Narrative\NarrativeResearchProvider;
use App\Services\Narrative\NarrativeSourceCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Token-level news research via the GDELT 2.1 DOC API (free, no key,
 * server-side only).
 *
 * Unlike the pump evidence engine's per-event window, this searches a BROAD
 * window (project age → now) for articles whose TITLE names this token. Any
 * failure (timeout, non-200, non-JSON) returns [] and logs one line — the
 * report continues on internal evidence only. It NEVER fabricates a source or a
 * date. Responses are cached.
 *
 * Currently unreachable in the dev network (see docs); the provider degrades
 * cleanly and a test pins that behaviour.
 */
class GdeltNarrativeResearchProvider implements NarrativeResearchProvider
{
    private bool $lastCallFailed = false;

    private int $requestsRemaining;

    public function __construct()
    {
        $this->requestsRemaining = max(0, (int) config('narrative.providers.gdelt.max_requests_per_run', 20));
    }

    public function name(): string
    {
        return 'gdelt';
    }

    public function isAvailable(): bool
    {
        return (bool) config('narrative.providers.gdelt.enabled', true)
            && $this->requestsRemaining > 0;
    }

    public function lastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    /**
     * @return list<NarrativeSourceCandidate>
     */
    public function research(NarrativeResearchContext $context): array
    {
        $this->lastCallFailed = false;

        if (! $context->hasResolvableIdentity() || ! $this->isAvailable()) {
            return [];
        }

        $lookbackDays = max(7, (int) config('narrative.providers.gdelt.lookback_days', 120));
        $end = $context->now;
        $start = $context->section === NarrativeResearchContext::SECTION_ORIGIN
            ? ($context->earliestPairCreatedAt ?? $context->firstObservedAt ?? $end->subDays($lookbackDays))->subDays(30)
            : ($context->firstObservedAt ?? $context->earliestPairCreatedAt ?? $end->subDays($lookbackDays));

        // Never look back further than the configured cap.
        if ($start->lessThan($end->subDays($lookbackDays))) {
            $start = $end->subDays($lookbackDays);
        }

        $articles = $this->search($this->buildQuery($context), $start, $end, $context);
        $name = mb_strtolower($context->name);

        $out = [];
        foreach ($articles as $article) {
            $title = (string) ($article['title'] ?? '');
            if ($title === '' || ! $this->titleNamesToken($title, $name)) {
                continue; // ticker-collision guard
            }
            $url = is_string($article['url'] ?? null) ? $article['url'] : null;
            if ($url === null) {
                continue;
            }
            $domain = mb_strtolower((string) ($article['domain'] ?? ''));
            $publishedAt = $this->parseSeenDate($article['seendate'] ?? null);
            $trusted = $this->domainTrusted($domain);

            $out[] = new NarrativeSourceCandidate(
                section: $context->section,
                sourceType: $this->isReference($domain) ? TokenNarrativeSource::TYPE_REFERENCE : TokenNarrativeSource::TYPE_NEWS,
                sourceName: $domain !== '' ? $domain : 'news',
                sourceUrl: $url,
                title: mb_substr($title, 0, 500),
                publishedAt: $publishedAt,
                claim: sprintf(
                    'Article "%s" (%s%s) refers to %s.',
                    $title,
                    $domain !== '' ? $domain : 'unknown source',
                    $publishedAt !== null ? ', '.$publishedAt->toDateString() : '',
                    $context->name,
                ),
                relevanceScore: 40 + ($trusted ? 20 : 0) + ($this->isReference($domain) ? 15 : 0),
                confidence: $this->isReference($domain) || $trusted
                    ? TokenNarrativeSource::CONFIDENCE_MEDIUM
                    : TokenNarrativeSource::CONFIDENCE_LOW,
                provider: $this->name(),
            );
        }

        return $out;
    }

    private function buildQuery(NarrativeResearchContext $context): string
    {
        $name = $this->sanitize($context->name);
        $qualifiers = ['crypto', 'token', 'memecoin', 'cryptocurrency'];
        if ($context->symbol !== '' && mb_strlen($context->symbol) >= 3) {
            $qualifiers[] = $this->sanitize($context->symbol);
        }

        return '"'.$name.'" ('.implode(' OR ', $qualifiers).')';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function search(string $query, CarbonImmutable $start, CarbonImmutable $end, NarrativeResearchContext $context): array
    {
        $cacheKey = 'narrative:gdelt:'.sha1($query.'|'.$context->section.'|'.$start->toDateString().'|'.$end->toDateString());
        $cacheTtl = max(1, (int) config('narrative.research.provider_cache_hours', 6)) * 3600;

        /** @var list<array<string,mixed>>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->requestsRemaining <= 0) {
            return [];
        }
        $this->requestsRemaining--;

        $base = (string) config('narrative.providers.gdelt.base_url', 'https://api.gdeltproject.org/api/v2/doc/doc');
        $max = max(1, min(75, (int) config('narrative.providers.gdelt.max_results_per_query', 15)));

        try {
            $response = Http::timeout((int) config('narrative.providers.gdelt.timeout', 8))
                ->connectTimeout((int) config('narrative.providers.gdelt.connect_timeout', 4))
                ->acceptJson()
                ->get($base, [
                    'query' => $query,
                    'mode' => 'ArtList',
                    'format' => 'json',
                    'startdatetime' => $start->utc()->format('YmdHis'),
                    'enddatetime' => $end->utc()->format('YmdHis'),
                    'maxrecords' => $max,
                    'sort' => 'DateAsc',
                ]);
        } catch (Throwable $e) {
            $this->lastCallFailed = true;
            Log::warning('Narrative GDELT lookup failed (transport)', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            $this->lastCallFailed = true;
            Log::warning('Narrative GDELT lookup failed (http)', ['status' => $response->status()]);

            return [];
        }

        $articles = $response->json('articles');
        if (! is_array($articles)) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = array_values(array_filter($articles, 'is_array'));
        Cache::put($cacheKey, $rows, $cacheTtl);

        return $rows;
    }

    private function titleNamesToken(string $title, string $lowerName): bool
    {
        if (mb_strlen($lowerName) < 3) {
            return false;
        }
        $haystack = ' '.mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title)).' ';
        $needle = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lowerName));

        return $needle !== '' && str_contains($haystack, ' '.$needle.' ');
    }

    private function parseSeenDate(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function domainTrusted(string $domain): bool
    {
        return $this->domainInList($domain, (array) config('narrative.trusted_domains', []));
    }

    private function isReference(string $domain): bool
    {
        return $this->domainInList($domain, (array) config('narrative.reference_domains', []));
    }

    /**
     * @param  list<string>  $list
     */
    private function domainInList(string $domain, array $list): bool
    {
        if ($domain === '') {
            return false;
        }
        foreach ($list as $entry) {
            $entry = mb_strtolower(trim((string) $entry));
            if ($entry !== '' && ($domain === $entry || str_ends_with($domain, '.'.$entry))) {
                return true;
            }
        }

        return false;
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/["()]+/', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
