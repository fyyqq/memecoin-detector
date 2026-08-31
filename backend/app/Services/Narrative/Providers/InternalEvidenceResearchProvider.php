<?php

declare(strict_types=1);

namespace App\Services\Narrative\Providers;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Models\QualificationEvent;
use App\Models\TokenNarrativeSource;
use App\Services\Narrative\NarrativeResearchContext;
use App\Services\Narrative\NarrativeResearchProvider;
use App\Services\Narrative\NarrativeSourceCandidate;

/**
 * The always-available research provider. PostgreSQL only — no HTTP.
 *
 * It turns what our own system already knows into narrative sources:
 *
 *   origin      — the token's stored metadata links (official), plus any
 *                 ORIGIN / TOKEN_METADATA Evidence rows collected for its pumps.
 *   popularity  — NEWS Evidence (news), RELATED_TOKEN / MARKET Evidence and the
 *                 token's own PumpEvents + $5M-crossing events (market timing).
 *
 * Market timing is recorded as neutral fact ("volume rose sharply after…"),
 * never as proof of causation.
 */
class InternalEvidenceResearchProvider implements NarrativeResearchProvider
{
    public function name(): string
    {
        return 'internal';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function lastCallFailed(): bool
    {
        return false;
    }

    /**
     * @return list<NarrativeSourceCandidate>
     */
    public function research(NarrativeResearchContext $context): array
    {
        return $context->section === NarrativeResearchContext::SECTION_ORIGIN
            ? $this->originSources($context)
            : $this->popularitySources($context);
    }

    /**
     * @return list<NarrativeSourceCandidate>
     */
    private function originSources(NarrativeResearchContext $context): array
    {
        $out = [];
        $token = $context->token;

        if ($context->websiteUrl !== null) {
            $out[] = new NarrativeSourceCandidate(
                section: 'origin',
                sourceType: TokenNarrativeSource::TYPE_OFFICIAL,
                sourceName: $context->websiteDomain ?? 'project website',
                sourceUrl: $context->websiteUrl,
                title: 'Official project website',
                publishedAt: null,
                claim: sprintf('DexScreener pair metadata lists an official website (%s) for %s.', $context->websiteDomain ?? $context->websiteUrl, $context->name),
                relevanceScore: 55,
                confidence: TokenNarrativeSource::CONFIDENCE_MEDIUM,
                provider: $this->name(),
            );
        }

        foreach ([['twitter', $context->twitterUrl, 'X / Twitter'], ['telegram', $context->telegramUrl, 'Telegram']] as [$kind, $url, $label]) {
            if ($url === null) {
                continue;
            }
            $out[] = new NarrativeSourceCandidate(
                section: 'origin',
                sourceType: TokenNarrativeSource::TYPE_SOCIAL,
                sourceName: $label,
                sourceUrl: $url,
                title: sprintf('Official %s account', $label),
                publishedAt: null,
                claim: sprintf('DexScreener pair metadata links an official %s account for %s.', $label, $context->name),
                relevanceScore: 38,
                confidence: TokenNarrativeSource::CONFIDENCE_LOW,
                provider: $this->name(),
            );
        }

        $evidence = Evidence::query()
            ->where('token_id', $token->id)
            ->whereIn('category', [Evidence::CATEGORY_ORIGIN, Evidence::CATEGORY_TOKEN_METADATA])
            ->orderByDesc('relevance_score')
            ->limit(20)
            ->get();

        foreach ($evidence as $row) {
            $out[] = $this->fromEvidence($row, 'origin', TokenNarrativeSource::TYPE_REFERENCE);
        }

        return $this->dedupeByHash($out);
    }

    /**
     * @return list<NarrativeSourceCandidate>
     */
    private function popularitySources(NarrativeResearchContext $context): array
    {
        $out = [];
        $token = $context->token;

        $evidence = Evidence::query()
            ->where('token_id', $token->id)
            ->whereIn('category', [Evidence::CATEGORY_NEWS, Evidence::CATEGORY_RELATED_TOKEN, Evidence::CATEGORY_MARKET])
            ->orderByDesc('relevance_score')
            ->limit(30)
            ->get();

        foreach ($evidence as $row) {
            $type = match ($row->category) {
                Evidence::CATEGORY_NEWS => TokenNarrativeSource::TYPE_NEWS,
                Evidence::CATEGORY_RELATED_TOKEN => TokenNarrativeSource::TYPE_MARKET,
                default => TokenNarrativeSource::TYPE_MARKET,
            };
            $out[] = $this->fromEvidence($row, 'popularity', $type);
        }

        // The token's own observed pumps — market-timing anchors for the chronology.
        $pumps = PumpEvent::query()
            ->where('token_id', $token->id)
            ->orderBy('started_at')
            ->limit(10)
            ->get();

        foreach ($pumps as $pump) {
            $mc = $pump->market_cap_change_pct;
            $out[] = new NarrativeSourceCandidate(
                section: 'popularity',
                sourceType: TokenNarrativeSource::TYPE_MARKET,
                sourceName: 'internal market observation',
                sourceUrl: null,
                title: 'Detected observed pump',
                publishedAt: $pump->peak_at,
                claim: sprintf(
                    'Our detector observed a pump of %s%% market cap for %s between %s and %s (detection confidence: %s). Market timing only — not a catalyst.',
                    $mc !== null ? round($mc) : '?',
                    $context->name,
                    $pump->started_at?->toDateString() ?? '?',
                    $pump->peak_at?->toDateString() ?? '?',
                    $pump->confidence,
                ),
                relevanceScore: 60,
                confidence: TokenNarrativeSource::CONFIDENCE_MEDIUM,
                provider: $this->name(),
            );
        }

        // $5M-crossing events (Step 20) — a milestone timestamp.
        $crossings = QualificationEvent::query()
            ->where('token_id', $token->id)
            ->orderBy('crossed_at')
            ->get();

        foreach ($crossings as $crossing) {
            $out[] = new NarrativeSourceCandidate(
                section: 'popularity',
                sourceType: TokenNarrativeSource::TYPE_MARKET,
                sourceName: 'internal qualification event',
                sourceUrl: null,
                title: 'Crossed the $5M market-cap threshold',
                publishedAt: $crossing->crossed_at,
                claim: sprintf(
                    '%s first crossed a $5M %s market cap on %s.',
                    $context->name,
                    $crossing->type === QualificationEvent::TYPE_HISTORICAL_VERIFIED ? 'verified historical' : 'observed',
                    $crossing->crossed_at?->toDateString() ?? '?',
                ),
                relevanceScore: 50,
                confidence: TokenNarrativeSource::CONFIDENCE_MEDIUM,
                provider: $this->name(),
            );
        }

        if ($context->earliestPairCreatedAt !== null) {
            $out[] = new NarrativeSourceCandidate(
                section: 'popularity',
                sourceType: TokenNarrativeSource::TYPE_MARKET,
                sourceName: 'internal market observation',
                sourceUrl: null,
                title: 'Earliest DEX pool creation',
                publishedAt: $context->earliestPairCreatedAt,
                claim: sprintf(
                    'The earliest DEX liquidity pool for %s was created on %s (pool creation, not necessarily token deploy).',
                    $context->name,
                    $context->earliestPairCreatedAt->toDateString(),
                ),
                relevanceScore: 40,
                confidence: TokenNarrativeSource::CONFIDENCE_MEDIUM,
                provider: $this->name(),
            );
        }

        return $this->dedupeByHash($out);
    }

    private function fromEvidence(Evidence $row, string $section, string $sourceType): NarrativeSourceCandidate
    {
        $isMarketTiming = in_array($row->category, [Evidence::CATEGORY_MARKET, Evidence::CATEGORY_RELATED_TOKEN], true);

        return new NarrativeSourceCandidate(
            section: $section,
            sourceType: $row->source_url !== null && $row->category === Evidence::CATEGORY_NEWS
                ? TokenNarrativeSource::TYPE_NEWS
                : ($isMarketTiming ? TokenNarrativeSource::TYPE_MARKET : $sourceType),
            sourceName: $row->source ?? 'internal evidence',
            sourceUrl: $row->source_url,
            title: $row->title,
            publishedAt: $row->published_at,
            claim: (string) $row->summary,
            relevanceScore: min(80, (int) $row->relevance_score),
            confidence: in_array($row->confidence, ['low', 'medium', 'high'], true) ? $row->confidence : 'low',
            provider: $this->name(),
        );
    }

    /**
     * @param  list<NarrativeSourceCandidate>  $candidates
     * @return list<NarrativeSourceCandidate>
     */
    private function dedupeByHash(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $hash = $candidate->dedupeHash();
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $out[] = $candidate;
        }

        return $out;
    }
}
