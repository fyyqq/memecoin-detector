<?php

declare(strict_types=1);

namespace App\Services\Evidence\Collectors;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Evidence\EvidenceCandidate;
use App\Services\Evidence\EvidenceCollector;
use App\Services\Evidence\EvidenceWindow;
use Carbon\CarbonImmutable;

/**
 * TOKEN_METADATA / ORIGIN evidence — from ALREADY STORED token metadata only.
 *
 * **No external HTTP.** Records neutral facts about what project resources are
 * linked and how old the token's earliest DEX pool is. It NEVER infers creator
 * intent, purpose, or narrative — DexScreener does not even expose a token
 * description. Language is "Project has a linked website", not "the token was
 * created to…".
 */
class TokenMetadataEvidenceCollector implements EvidenceCollector
{
    public function name(): string
    {
        return 'token_metadata';
    }

    public function isExternal(): bool
    {
        return false;
    }

    /**
     * @return list<EvidenceCandidate>
     */
    public function collect(PumpEvent $event, Token $token, EvidenceWindow $window): array
    {
        $out = [];
        $observedAt = $token->metadata_updated_at ?? $token->last_observed_at;
        $ref = 'token:'.$token->id;

        $links = [
            ['url' => $token->website_url, 'label' => 'website'],
            ['url' => $token->twitter_url, 'label' => 'X/Twitter profile'],
            ['url' => $token->telegram_url, 'label' => 'Telegram channel'],
        ];

        $present = array_values(array_filter($links, fn (array $l): bool => is_string($l['url']) && $l['url'] !== ''));

        foreach ($present as $link) {
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_ORIGIN,
                source: 'dexscreener',
                sourceUrl: $link['url'],
                title: 'Linked '.$link['label'],
                observedAt: $observedAt,
                publishedAt: null,
                relevanceScore: $link['label'] === 'website' ? 45 : 38,
                confidence: Evidence::CONFIDENCE_MEDIUM,
                summary: 'Stored DexScreener metadata lists a linked '.$link['label'].' for this token'
                    .($this->host($link['url']) !== null ? ' ('.$this->host($link['url']).')' : '').'.',
                rawReference: $ref,
            );
        }

        if ($present === []) {
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_ORIGIN,
                source: 'dexscreener',
                sourceUrl: null,
                title: 'No linked project resources',
                observedAt: $observedAt,
                publishedAt: null,
                relevanceScore: 20,
                confidence: Evidence::CONFIDENCE_LOW,
                summary: 'No website or social links are present in the stored DexScreener metadata for this token.',
                rawReference: $ref,
            );
        }

        // Earliest observed DEX pool age relative to the pump.
        if ($token->earliest_pair_created_at !== null && $event->started_at !== null) {
            /** @var CarbonImmutable $poolAt */
            $poolAt = $token->earliest_pair_created_at;
            $daysBefore = (int) round($poolAt->diffInDays($event->started_at, false));
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_TOKEN_METADATA,
                source: 'internal',
                sourceUrl: null,
                title: 'Earliest observed DEX pool',
                observedAt: $poolAt,
                publishedAt: null,
                relevanceScore: 50,
                confidence: Evidence::CONFIDENCE_MEDIUM,
                summary: sprintf(
                    "The token's earliest observed DEX pool was created on %s — about %d day(s) before this pump event started. (Pool creation is not the same as token deployment.)",
                    $poolAt->toDateString(),
                    max(0, $daysBefore),
                ),
                rawReference: 'token:'.$token->id,
            );
        }

        return $out;
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? preg_replace('/^www\./', '', $host) : null;
    }
}
