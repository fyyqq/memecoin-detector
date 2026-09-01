<?php

declare(strict_types=1);

namespace App\Services\Ranking\Providers;

use App\Models\MonthlyRanking;
use App\Services\Ranking\MonthlyCandidate;
use App\Services\Ranking\MonthlyChampionResearchProvider;
use App\Services\Ranking\MonthlyChampionService;
use App\Services\Ranking\MonthlyResearchCandidate;
use App\Services\Ranking\MonthlyResearchContext;
use App\Services\Ranking\MonthlyResearchSource;
use Carbon\CarbonImmutable;

/**
 * Always-on baseline provider — our own `MarketSnapshot` history for the month.
 *
 * Returns the eligible / thinly-observed candidates from
 * {@see MonthlyChampionService::computeCandidates} as
 * {@see MonthlyResearchCandidate}s tagged `internal_observed`, so the research
 * service uses our real data when we have it and only falls back to external
 * evidence when we do not.
 */
class InternalObservedMonthlyResearchProvider implements MonthlyChampionResearchProvider
{
    public function __construct(private readonly MonthlyChampionService $champions) {}

    public function name(): string
    {
        return 'internal_observed';
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
     * @return list<MonthlyResearchCandidate>
     */
    public function research(MonthlyResearchContext $context): array
    {
        $now = CarbonImmutable::now();
        $candidates = $this->champions->computeCandidates($context->window, $context->bucket, $now);

        $out = [];
        foreach ($candidates as $candidate) {
            if (! in_array($candidate->status, [
                MonthlyCandidate::STATUS_ELIGIBLE,
                MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION,
            ], true)) {
                continue;
            }

            $token = $candidate->token;
            $out[] = new MonthlyResearchCandidate(
                name: (string) ($token->name ?? $token->symbol ?? 'unknown'),
                symbol: (string) ($token->symbol ?? 'unknown'),
                chainId: (string) $token->chain_id,
                tokenAddress: $token->token_address,
                imageUrl: $token->image_url,
                baselineMarketCap: $candidate->baselineMarketCap,
                peakMarketCap: $candidate->monthMarketCap ?? $candidate->peakMarketCap,
                volumeUsd: $candidate->monthlyVolumeUsd,
                holderCount: $candidate->holderCount,
                launchDate: $token->earliest_pair_created_at,
                ageUncertain: false,
                sourceType: MonthlyRanking::SOURCE_INTERNAL_OBSERVED,
                suggestedConfidence: ($candidate->observationCoverageRatio ?? 0.0) >= 0.5
                    ? MonthlyRanking::CONFIDENCE_HIGH
                    : ($candidate->status === MonthlyCandidate::STATUS_ELIGIBLE
                        ? MonthlyRanking::CONFIDENCE_MEDIUM
                        : MonthlyRanking::CONFIDENCE_LOW),
                sources: [new MonthlyResearchSource(
                    name: 'internal detector observations',
                    url: null,
                    claim: sprintf(
                        '%d in-month MarketSnapshots, coverage %.2f, baseline $%s -> peak $%s',
                        $candidate->observationCount,
                        $candidate->observationCoverageRatio ?? 0.0,
                        number_format((float) $candidate->baselineMarketCap),
                        number_format((float) $candidate->peakMarketCap),
                    ),
                    publishedAt: null,
                    credibility: 'primary_market_data',
                )],
                explanation: 'Highest deterministic monthly-performance score among tokens this detector observed in the month for this chain bucket.',
                observationCount: $candidate->observationCount,
                observationCoverageRatio: $candidate->observationCoverageRatio,
                tokenId: (int) $token->id,
                performanceScore: $candidate->performanceScore,
                holderStrength: $candidate->holderStrength,
                volumeStrength: $candidate->volumeStrength,
                marketCapStrength: $candidate->marketCapStrength,
                marketCapGrowthPct: $candidate->marketCapGrowthPct,
                peakExpansionRatio: $candidate->peakExpansionRatio,
                activityScore: $candidate->activityScore,
            );
        }

        return $out;
    }
}
