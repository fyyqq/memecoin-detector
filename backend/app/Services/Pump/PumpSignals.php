<?php

declare(strict_types=1);

namespace App\Services\Pump;

/**
 * The four raw pump signals computed between two observations (start → peak).
 *
 * `volumeH24ChangeRatio` / `txnsH24ChangeRatio` are ROLLING 24h ratios
 * (`peak.volume_h24 / start.volume_h24` etc.) — directional confirmation only,
 * NOT interval volume / transaction counts. See config/pump.php.
 *
 * Any value is `null` when its underlying snapshot fields are missing or the
 * baseline is <= 0 — never coerced to `0`.
 */
final readonly class PumpSignals
{
    public function __construct(
        public ?float $marketCapChangePct,
        public ?float $priceChangePct,
        public ?float $volumeH24ChangeRatio,
        public ?float $txnsH24ChangeRatio,
    ) {}
}
