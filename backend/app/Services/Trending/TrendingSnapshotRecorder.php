<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\TrendingSnapshot;
use Carbon\CarbonImmutable;

/**
 * Upserts one `trending_snapshots` row per (chain, token, timeframe,
 * capture_bucket). Re-running `collect-trending` inside the same 5-minute bucket
 * refreshes the row; a new bucket appends a new row (history).
 */
class TrendingSnapshotRecorder
{
    /**
     * @param  array<string,int|null>  $tokenIdByKey  "chain:addr" => token_id
     * @param  string  $memecoinVerdict  MemecoinClassifier verdict (TRUE for an eligible row)
     * @param  CarbonImmutable|null  $bestCreatedAt  the real earliest_pair_created_at (tracked / enriched), else the meta pair
     */
    public function record(
        TrendingCandidate $candidate,
        string $timeframe,
        int $captureBucket,
        int $rank,
        TrackedTrendScore $score,
        int $appearances,
        array $tokenIdByKey,
        CarbonImmutable $now,
        string $memecoinVerdict = MemecoinClassifier::TRUE,
        ?CarbonImmutable $bestCreatedAt = null,
    ): void {
        TrendingSnapshot::query()->updateOrCreate(
            [
                'chain_id' => $candidate->chainId,
                'token_address' => $candidate->tokenAddress,
                'timeframe' => $timeframe,
                'capture_bucket' => $captureBucket,
            ],
            [
                'token_id' => $tokenIdByKey[$candidate->key()] ?? null,
                'pair_address' => $candidate->pairAddress,
                'dex_id' => $candidate->dexId,
                'symbol' => $candidate->symbol,
                'name' => $candidate->name,
                'is_memecoin_candidate' => $memecoinVerdict,
                'trend_rank' => $rank,
                'tracked_trend_score' => $score->score,
                'trend_score_components' => $score->components,
                'trend_appearances' => $appearances,
                'market_cap' => $candidate->marketCap,
                'liquidity_usd' => $candidate->liquidityUsd,
                'volume_usd' => $candidate->volumeFor($timeframe),
                'price_change_pct' => $candidate->priceChangeFor($timeframe),
                'transaction_count' => $candidate->txnsFor($timeframe),
                'pair_created_at' => $bestCreatedAt ?? $candidate->pairCreatedAt,
                'trending_meta_slug' => $candidate->trendingMetaSlug,
                'trending_meta_name' => $candidate->trendingMetaName,
                'source' => TrendingSnapshot::SOURCE,
                'captured_at' => $now,
            ],
        );
    }
}
