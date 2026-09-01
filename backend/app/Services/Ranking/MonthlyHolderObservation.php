<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use Carbon\CarbonImmutable;

/**
 * One token's monthly-representative holder count for the "Monthly Top Memecoins"
 * holder pass (Step 25).
 *
 *   holderCount  — the monthly MAX GeckoTerminal `/info` `holders.count` seen so
 *                  far this calendar month, or null when GeckoTerminal has never
 *                  returned a count (UNKNOWN — never a current count standing in
 *                  for a past month, never fabricated).
 *   checkedAt    — when this token was last polled (fresh fetch → now;
 *                  cooldown-skipped → the prior timestamp; never fetched → null).
 */
final readonly class MonthlyHolderObservation
{
    public function __construct(
        public ?int $holderCount,
        public ?CarbonImmutable $checkedAt,
    ) {}
}
