<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\Token;
use Illuminate\Support\Collection;

/**
 * Collapses ticker/name-squatting duplicates in the "🔥 Recently Crossed $5M"
 * feed: when several DISTINCT contracts share `(chain_id, symbol, name)` — e.g.
 * three separate "JINQIAN / Money Mushroom" contracts on `robinhood` — only one
 * row should be shown, and only one should get the Post-30-Day stamp.
 *
 * The winner is the record with the SANEST market structure: the highest
 * `liquidity_usd / market_cap` ratio, CAPPED at 0.5 so a dead $6K-market-cap
 * copycat (whose few thousand dollars of liquidity happen to "balance" its tiny
 * cap → ratio ~1.25) cannot beat the real coin (a real $69M cap on $1.2M
 * liquidity → ratio ~0.017). Tie-breaks: longer snapshot history → earliest
 * `first_observed_at` → lowest id.
 *
 * A token with no symbol is unidentifiable and is never merged.
 *
 * PostgreSQL-only, pure. Callers must eager-load `latestSnapshot` and
 * `withCount('marketSnapshots')`.
 */
class SameTickerCollapser
{
    private const LIQ_RATIO_CAP = 0.5;

    /**
     * @param  Collection<int, Token>  $tokens
     * @return Collection<int, Token> one winner per identity group
     */
    public static function winners(Collection $tokens): Collection
    {
        return $tokens
            ->groupBy(static fn (Token $token): string => self::identityKey($token))
            ->map(static fn (Collection $group): Token => $group->count() === 1
                ? $group->first()
                : self::pickSanest($group))
            ->values();
    }

    private static function identityKey(Token $token): string
    {
        $symbol = mb_strtolower(trim((string) $token->symbol));
        if ($symbol === '') {
            return 'id:'.$token->id; // unidentifiable — never merged
        }

        return $token->chain_id.'|'.$symbol.'|'.mb_strtolower(trim((string) $token->name));
    }

    /**
     * @param  Collection<int, Token>  $group
     */
    private static function pickSanest(Collection $group): Token
    {
        return $group->sort(static function (Token $a, Token $b): int {
            $scoreA = self::sanityScore($a);
            $scoreB = self::sanityScore($b);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $snapsA = (int) ($a->market_snapshots_count ?? 0);
            $snapsB = (int) ($b->market_snapshots_count ?? 0);
            if ($snapsA !== $snapsB) {
                return $snapsB <=> $snapsA;
            }

            $firstA = $a->first_observed_at?->getTimestamp() ?? PHP_INT_MAX;
            $firstB = $b->first_observed_at?->getTimestamp() ?? PHP_INT_MAX;
            if ($firstA !== $firstB) {
                return $firstA <=> $firstB;
            }

            return $a->id <=> $b->id;
        })->first();
    }

    private static function sanityScore(Token $token): float
    {
        $snapshot = $token->relationLoaded('latestSnapshot') ? $token->latestSnapshot : null;
        $marketCap = (float) ($snapshot?->market_cap ?? 0.0);
        if ($marketCap <= 0.0) {
            return 0.0;
        }

        $liquidity = (float) ($snapshot?->liquidity_usd ?? 0.0);

        return min($liquidity / $marketCap, self::LIQ_RATIO_CAP);
    }
}
