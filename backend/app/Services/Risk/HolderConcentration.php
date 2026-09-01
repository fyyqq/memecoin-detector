<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Effective holder-concentration summary (Step 24).
 *
 * "Effective" = after excluding burn / LP-pair / known-CEX / bridge / locker
 * addresses. `null` means UNKNOWN — a missing holder list never fakes a count
 * and an exchange / LP address is never treated as an individual whale.
 */
final class HolderConcentration
{
    public function __construct(
        public readonly bool $available,
        public readonly ?int $holderCount,
        public readonly ?float $holdersPerMillionMc,
        public readonly ?float $top1EffectivePct,
        public readonly ?float $top5EffectivePct,
        public readonly ?float $top10EffectivePct,
        public readonly ?float $creatorPct,
        public readonly ?float $ownerPct,
        public readonly int $excludedHolders,
        public readonly ?string $source,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, null, null, null, null, null, null, null, 0, null);
    }
}
