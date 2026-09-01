<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * The FIVE fixed display buckets for "Monthly Top Memecoins" (Step 22, corrected).
 *
 * Every `(year, month, chain_bucket)` has AT MOST ONE champion — 12 months × 5
 * buckets = at most 60 rows a year.
 *
 *   solana     — chain_id = solana
 *   robinhood  — chain_id = robinhood
 *   bsc        — chain_id = bsc
 *   base       — chain_id = base
 *   other      — every other chain_id (never hard-coded; the token keeps its
 *                real chain_id, only `monthly_rankings.chain_bucket` says "other")
 */
final class ChainBucket
{
    public const SOLANA = 'solana';

    public const ROBINHOOD = 'robinhood';

    public const BSC = 'bsc';

    public const BASE = 'base';

    public const OTHER = 'other';

    /** Canonical order — the API and UI always render all five, in this order. */
    public const ALL = [
        self::SOLANA,
        self::ROBINHOOD,
        self::BSC,
        self::BASE,
        self::OTHER,
    ];

    /** The four buckets that map to a single concrete chain_id. */
    public const CORE = [
        self::SOLANA,
        self::ROBINHOOD,
        self::BSC,
        self::BASE,
    ];

    /** Deterministic: a token's real `chain_id` -> its display bucket. */
    public static function forChain(?string $chainId): string
    {
        $normalized = mb_strtolower(trim((string) $chainId));

        return in_array($normalized, self::CORE, true) ? $normalized : self::OTHER;
    }

    public static function isValid(string $bucket): bool
    {
        return in_array($bucket, self::ALL, true);
    }

    /** Human display label for a bucket. */
    public static function label(string $bucket): string
    {
        return match ($bucket) {
            self::SOLANA => 'Solana',
            self::ROBINHOOD => 'Robinhood',
            self::BSC => 'BSC',
            self::BASE => 'Base',
            self::OTHER => 'Other',
            default => ucfirst($bucket),
        };
    }
}
