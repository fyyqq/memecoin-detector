<?php

declare(strict_types=1);

namespace App\Services\Historical;

use Carbon\CarbonImmutable;

/**
 * Result of a GeckoTerminal historical-price + supply lookup for one token.
 *
 * The market-cap figure here is **reconstructed** (FDV basis:
 * historical high price x immutable total supply) — GeckoTerminal never
 * provides a market cap. Only produced when the supply-safety gate passes.
 *
 * `outcome`:
 *   estimate        — FDV-basis estimate computed (see estimateUsd)
 *   supply_unsafe   — mint is mutable / mechanics unsafe → reject
 *   supply_missing  — no defensible total supply → reject
 *   no_pool         — no pool found for the token
 *   no_price        — no usable OHLCV price history
 *   unavailable     — transport failure / budget exhausted / disabled
 */
final readonly class GeckoTerminalLookup
{
    public function __construct(
        public string $outcome,
        public ?string $poolAddress = null,
        public ?float $peakPriceUsd = null,
        public ?float $totalSupply = null,
        public ?float $estimateUsd = null,
        public ?CarbonImmutable $peakAt = null,
        public ?CarbonImmutable $windowStart = null,
        public ?CarbonImmutable $windowEnd = null,
        public ?string $confidence = null,
        public ?string $note = null,
    ) {}

    public static function unavailable(string $note): self
    {
        return new self('unavailable', note: $note);
    }
}
