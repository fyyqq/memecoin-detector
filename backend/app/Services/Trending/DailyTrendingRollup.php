<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\DailyTrendingRanking;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use Carbon\CarbonImmutable;

/**
 * Maintains the daily trending archive (`daily_trending_rankings`).
 *
 * One row per (date, chain_bucket, timeframe, token_address). Each
 * `collect-trending` run folds the current capture into today's row:
 *   best_rank   = MIN, best_score = MAX, peak_* = MAX, appearances += 1,
 *   last_seen_at = now, first_seen_at preserved.
 *
 * This is what makes "trending yesterday" survive a token dropping out of
 * trending today — the row is never deleted except by retention cleanup.
 */
class DailyTrendingRollup
{
    /**
     * @param  array<string,int|null>  $tokenIdByKey  "chain:addr" => token_id
     * @return int rows touched
     */
    public function record(
        TrendingCandidate $candidate,
        string $timeframe,
        int $rank,
        float $score,
        array $tokenIdByKey,
        CarbonImmutable $now,
    ): int {
        $date = $now->toDateString();
        $bucket = ChainBucket::forChain($candidate->chainId);
        $volume = $candidate->volumeFor($timeframe);

        /** @var DailyTrendingRanking|null $existing */
        $existing = DailyTrendingRanking::query()
            ->where('date', $date)
            ->where('chain_bucket', $bucket)
            ->where('timeframe', $timeframe)
            ->where('token_address', $candidate->tokenAddress)
            ->first();

        $tokenId = $tokenIdByKey[$candidate->key()] ?? $existing?->token_id;

        if ($existing === null) {
            DailyTrendingRanking::query()->create([
                'date' => $date,
                'chain_bucket' => $bucket,
                'timeframe' => $timeframe,
                'token_id' => $tokenId,
                'chain_id' => $candidate->chainId,
                'token_address' => $candidate->tokenAddress,
                'symbol' => $candidate->symbol,
                'name' => $candidate->name,
                'best_rank' => $rank,
                'best_score' => $score,
                'peak_market_cap' => $candidate->marketCap,
                'peak_volume' => $volume,
                'peak_liquidity' => $candidate->liquidityUsd,
                'appearances' => 1,
                'trending_meta_slug' => $candidate->trendingMetaSlug,
                'trending_meta_name' => $candidate->trendingMetaName,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            return 1;
        }

        $existing->fill([
            'token_id' => $tokenId,
            'symbol' => $candidate->symbol ?? $existing->symbol,
            'name' => $candidate->name ?? $existing->name,
            'best_rank' => min($existing->best_rank, $rank),
            'best_score' => max($existing->best_score, $score),
            'peak_market_cap' => $this->maxNullable($existing->peak_market_cap, $candidate->marketCap),
            'peak_volume' => $this->maxNullable($existing->peak_volume, $volume),
            'peak_liquidity' => $this->maxNullable($existing->peak_liquidity, $candidate->liquidityUsd),
            'appearances' => $existing->appearances + 1,
            'last_seen_at' => $now,
        ]);
        $existing->save();

        return 1;
    }

    /**
     * Backfill token_id on archive rows once a brand-new trending token has been
     * enriched into a Token within the same run.
     *
     * @param  array<string,int>  $tokenIdByKey
     */
    public function linkNewTokens(array $tokenIdByKey, CarbonImmutable $now): void
    {
        foreach ($tokenIdByKey as $key => $tokenId) {
            [$chain, $address] = array_pad(explode(':', $key, 2), 2, '');

            DailyTrendingRanking::query()
                ->where('date', $now->toDateString())
                ->where('chain_id', $chain)
                ->where('token_address', $address)
                ->whereNull('token_id')
                ->update(['token_id' => $tokenId]);
        }
    }

    private function maxNullable(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return max($a, $b);
    }
}
