<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Numeric pump-dump shape for a token — computed ONLY from our own
 * `market_snapshots` (+ `pump_events`). No chart images, no vision.
 *
 * A pump-then-crash contributes to `pump_dump_risk`, NOT to a "scam" label.
 */
final class ChartShape
{
    public function __construct(
        public readonly bool $sufficientHistory,
        public readonly ?float $peakToCurrentDrawdownPct,
        public readonly ?float $maxRunupPct,
        public readonly ?float $maxDrawdownPct,
        public readonly ?float $timeSincePeakHours,
        public readonly bool $roundTrip,
        public readonly bool $volumeCollapse,
        public readonly bool $severeShortPumpThenCollapse,
        public readonly int $snapshotsConsidered,
    ) {}

    public static function insufficient(int $snapshots): self
    {
        return new self(false, null, null, null, null, false, false, false, $snapshots);
    }
}
