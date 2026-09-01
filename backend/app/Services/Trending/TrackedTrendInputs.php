<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * The exact raw values {@see TrackedTrendScorer} reads for ONE token in ONE
 * timeframe. All nullable — a missing value drives a reduced component score, it
 * never becomes a fake zero.
 *
 * MARKET CAP IS NOT HERE ON PURPOSE — it is not an input to the trend score.
 */
final readonly class TrackedTrendInputs
{
    /**
     * @param  string  $timeframe  "6h" | "24h"
     * @param  int  $appearances  how many of the recent `persistenceWindow` captures this token trended
     * @param  int  $persistenceWindow  the window size (captures) persistence is measured against
     */
    public function __construct(
        public string $timeframe,
        public ?float $priceChangePct,
        public ?float $volumeUsd,
        public ?int $transactionCount,
        public ?float $liquidityUsd,
        public int $appearances = 1,
        public int $persistenceWindow = 12,
    ) {}

    /**
     * @return array<string, float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'timeframe' => $this->timeframe,
            'price_change_pct' => $this->priceChangePct,
            'volume_usd' => $this->volumeUsd,
            'transaction_count' => $this->transactionCount,
            'liquidity_usd' => $this->liquidityUsd,
            'appearances' => $this->appearances,
            'persistence_window' => $this->persistenceWindow,
        ];
    }
}
