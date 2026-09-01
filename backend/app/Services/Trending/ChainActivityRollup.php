<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\DailyChainActivity;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Recomputes today's "Chain Market Activity" rows (`daily_chain_activity`) — one
 * per chain bucket — from `tokens` + each token's LATEST `market_snapshot`.
 *
 * Aggregation rule (documented): exactly ONE volume / liquidity figure per
 * tracked token (its latest snapshot's representative-pair `volume_h24` /
 * `liquidity_usd`), so a token with many pools is never double-counted. Tokens
 * are excluded by {@see MarketIntegrityGate} first. `total_volume_usd` is
 * REPORTED volume — not claimed organic.
 */
class ChainActivityRollup
{
    /**
     * @return int rows written (always 5 — one per bucket)
     */
    public function recompute(CarbonImmutable $now): int
    {
        $activeSince = $now->subHours((int) config('trending.volume.active_within_hours', 48));
        $date = $now->toDateString();

        /** @var Collection<int, Token> $tokens */
        $tokens = Token::query()
            ->whereHas('marketSnapshots', fn ($q) => $q->where('observed_at', '>=', $activeSince))
            ->with(['latestSnapshot'])
            ->get();

        /** @var array<string,array{volume:float,liquidity:float,count:int,top:?Token,top_volume:float}> $byBucket */
        $byBucket = [];
        foreach (ChainBucket::ALL as $bucket) {
            $byBucket[$bucket] = ['volume' => 0.0, 'liquidity' => 0.0, 'count' => 0, 'top' => null, 'top_volume' => 0.0];
        }

        foreach ($tokens as $token) {
            $snapshot = $token->latestSnapshot;
            if ($snapshot === null || $snapshot->observed_at === null || $snapshot->observed_at->lessThan($activeSince)) {
                continue;
            }

            if (! MarketIntegrityGate::passes(
                $snapshot->volume_h24,
                $snapshot->liquidity_usd,
                $snapshot->market_cap,
                $snapshot->txns_h24,
            )) {
                continue;
            }

            $bucket = ChainBucket::forChain($token->chain_id);
            $volume = (float) ($snapshot->volume_h24 ?? 0.0);

            $byBucket[$bucket]['volume'] += $volume;
            $byBucket[$bucket]['liquidity'] += (float) ($snapshot->liquidity_usd ?? 0.0);
            $byBucket[$bucket]['count']++;

            if ($volume > $byBucket[$bucket]['top_volume']) {
                $byBucket[$bucket]['top_volume'] = $volume;
                $byBucket[$bucket]['top'] = $token;
            }
        }

        $written = 0;
        foreach ($byBucket as $bucket => $agg) {
            DailyChainActivity::query()->updateOrCreate(
                ['date' => $date, 'chain_bucket' => $bucket],
                [
                    'total_volume_usd' => round($agg['volume'], 2),
                    'total_liquidity_usd' => round($agg['liquidity'], 2),
                    'active_token_count' => $agg['count'],
                    'top_token_id' => $agg['top']?->id,
                    'top_token_address' => $agg['top']?->token_address,
                    'top_token_symbol' => $agg['top']?->symbol,
                    'top_token_volume' => $agg['top'] !== null ? round($agg['top_volume'], 2) : null,
                    'computed_at' => $now,
                ],
            );
            $written++;
        }

        return $written;
    }
}
