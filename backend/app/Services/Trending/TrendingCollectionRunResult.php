<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * Observability-only summary of one `memecoins:collect-trending` run.
 */
final class TrendingCollectionRunResult
{
    public int $captureBucket = 0;

    public int $metaCount = 0;

    public int $pairsSeen = 0;

    public int $uniqueTokens = 0;

    // Trending-Now eligibility funnel.
    public int $excludedNonMemecoin = 0;

    public int $excludedAmbiguousMemecoin = 0;

    public int $excludedCurrentMarketCap = 0;

    public int $excludedNoLiquidity = 0;

    public int $excludedNoVolume = 0;

    public int $excludedAgeUnknown = 0;

    public int $excludedTooOld = 0;

    public int $eligibleCandidates = 0;

    /** @var array<string,int> timeframe => candidates scored */
    public array $candidatesPerTimeframe = [];

    public int $snapshotsWritten = 0;

    public int $dailyRankingsUpserted = 0;

    public int $newTokensEnriched = 0;

    public int $enrichAttempted = 0;

    public int $chainActivityRowsWritten = 0;

    /** @var array<string,int> chain_id => unique tokens */
    public array $chainsSeen = [];

    public int $providerFailures = 0;

    public float $durationSeconds = 0.0;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'capture_bucket' => $this->captureBucket,
            'meta_count' => $this->metaCount,
            'pairs_seen' => $this->pairsSeen,
            'unique_tokens' => $this->uniqueTokens,
            'excluded_non_memecoin' => $this->excludedNonMemecoin,
            'excluded_ambiguous_memecoin' => $this->excludedAmbiguousMemecoin,
            'excluded_current_market_cap' => $this->excludedCurrentMarketCap,
            'excluded_no_liquidity' => $this->excludedNoLiquidity,
            'excluded_no_volume' => $this->excludedNoVolume,
            'excluded_age_unknown' => $this->excludedAgeUnknown,
            'excluded_too_old' => $this->excludedTooOld,
            'eligible_candidates' => $this->eligibleCandidates,
            'candidates_per_timeframe' => $this->candidatesPerTimeframe,
            'snapshots_written' => $this->snapshotsWritten,
            'daily_rankings_upserted' => $this->dailyRankingsUpserted,
            'new_tokens_enriched' => $this->newTokensEnriched,
            'enrich_attempted' => $this->enrichAttempted,
            'chain_activity_rows_written' => $this->chainActivityRowsWritten,
            'chains_seen' => $this->chainsSeen,
            'provider_failures' => $this->providerFailures,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
