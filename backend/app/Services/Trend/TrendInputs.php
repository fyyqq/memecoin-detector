<?php

declare(strict_types=1);

namespace App\Services\Trend;

use App\Models\Token;

/**
 * The exact raw values the {@see TrendScorer} reads — a token's peak state plus
 * its latest MarketSnapshot. All nullable: a missing value never becomes a fake
 * zero, it drives a reduced component score instead.
 */
final readonly class TrendInputs
{
    public function __construct(
        public ?float $priceChangeH24,
        public ?float $volumeH24,
        public ?float $liquidityUsd,
        public ?float $currentMarketCap,
        public ?float $observedPeakMarketCap,
        public ?int $txnsH24,
    ) {}

    public static function fromToken(Token $token): self
    {
        $snapshot = $token->latestSnapshot;

        return new self(
            priceChangeH24: $snapshot?->price_change_h24,
            volumeH24: $snapshot?->volume_h24,
            liquidityUsd: $snapshot?->liquidity_usd,
            currentMarketCap: $snapshot?->market_cap,
            observedPeakMarketCap: $token->observed_peak_market_cap,
            txnsH24: $snapshot?->txns_h24,
        );
    }

    /**
     * @return array<string, float|int|null>
     */
    public function toArray(): array
    {
        return [
            'price_change_h24' => $this->priceChangeH24,
            'volume_h24' => $this->volumeH24,
            'liquidity_usd' => $this->liquidityUsd,
            'current_market_cap' => $this->currentMarketCap,
            'observed_peak_market_cap' => $this->observedPeakMarketCap,
            'txns_h24' => $this->txnsH24,
        ];
    }
}
