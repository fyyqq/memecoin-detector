<?php

declare(strict_types=1);

namespace App\Services\Pump;

use Carbon\CarbonImmutable;

/**
 * A pump the {@see PumpDetector} found in one token's recent observation series.
 *
 * All timestamps are snapshot `observed_at` values. `endedAt` is `null` when the
 * peak observation IS the most recent one (still active); otherwise the pump has
 * passed its peak and `status` is `completed`.
 */
final readonly class PumpDetection
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public CarbonImmutable $peakAt,
        public ?CarbonImmutable $endedAt,
        public ?float $startMarketCap,
        public ?float $peakMarketCap,
        public ?float $startPriceUsd,
        public ?float $peakPriceUsd,
        public ?float $marketCapChangePct,
        public ?float $priceChangePct,
        public ?float $volumeH24ChangeRatio,
        public ?float $txnsH24ChangeRatio,
        public int $durationMinutes,
        public int $detectionScore,
        public string $confidence,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'started_at' => $this->startedAt,
            'peak_at' => $this->peakAt,
            'ended_at' => $this->endedAt,
            'start_market_cap' => $this->startMarketCap,
            'peak_market_cap' => $this->peakMarketCap,
            'start_price_usd' => $this->startPriceUsd,
            'peak_price_usd' => $this->peakPriceUsd,
            'market_cap_change_pct' => $this->marketCapChangePct,
            'price_change_pct' => $this->priceChangePct,
            'volume_h24_change_ratio' => $this->volumeH24ChangeRatio,
            'txns_h24_change_ratio' => $this->txnsH24ChangeRatio,
            'duration_minutes' => $this->durationMinutes,
            'detection_score' => $this->detectionScore,
            'confidence' => $this->confidence,
            'status' => $this->status,
        ];
    }
}
