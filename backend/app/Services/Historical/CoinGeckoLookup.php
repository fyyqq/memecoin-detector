<?php

declare(strict_types=1);

namespace App\Services\Historical;

use Carbon\CarbonImmutable;

/**
 * Result of a CoinGecko historical market-cap lookup for one token.
 *
 * `outcome`:
 *   verified       — a non-zero historical market cap point was found
 *   not_found      — CoinGecko has no coin for this contract (404)
 *   no_market_cap  — coin exists but every market_caps point is zero/absent
 *                    (circulating supply not verified) → treat as NOT verified
 *   unavailable    — transport failure / 429 / budget exhausted / disabled
 */
final readonly class CoinGeckoLookup
{
    public function __construct(
        public string $outcome,
        public ?string $coinId = null,
        public ?float $peakMarketCapUsd = null,
        public ?CarbonImmutable $peakAt = null,
        public ?CarbonImmutable $windowStart = null,
        public ?CarbonImmutable $windowEnd = null,
        public ?string $note = null,
        // Step 20 — the EARLIEST verified point that cleared the $5M threshold
        // (candled/sampled; not the exact tick). Feeds a HISTORICAL_VERIFIED
        // crossing event's `crossed_at`. Null unless `outcome === 'verified'`.
        public ?CarbonImmutable $firstCrossingAt = null,
    ) {}

    public static function verified(
        string $coinId,
        float $peak,
        CarbonImmutable $at,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        ?CarbonImmutable $firstCrossingAt = null,
    ): self {
        return new self('verified', $coinId, $peak, $at, $start, $end, firstCrossingAt: $firstCrossingAt);
    }

    public static function notFound(): self
    {
        return new self('not_found', note: 'coingecko: coin not found for contract');
    }

    public static function noMarketCap(string $coinId, ?string $note = null): self
    {
        return new self('no_market_cap', $coinId, note: $note ?? 'coingecko: market_caps all zero (circulating supply unverified)');
    }

    public static function unavailable(string $note): self
    {
        return new self('unavailable', note: $note);
    }
}
