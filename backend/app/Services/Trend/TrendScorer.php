<?php

declare(strict_types=1);

namespace App\Services\Trend;

use App\Models\Token;

/**
 * Computes the transparent 30-Day Trend Score.
 *
 * Pure and deterministic: same inputs → same score. Reads only the values in
 * {@see TrendInputs} (peak state + latest snapshot). No history, no network,
 * no randomness, no AI. All tuning lives in config/trend.php.
 *
 * Overall:
 *   trend_score = round( Σ(weight_i · component_i) / Σ(weight_i) , 1 )
 *
 * Components (each clamped to 0-100):
 *   price_momentum       50 · (1 + tanh(price_change_h24 / momentum_reference_pct))
 *   volume_liquidity     100 · r / (r + reference_ratio),  r = volume_h24 / liquidity_usd
 *   peak_retention       100 · clamp(current_market_cap / observed_peak_market_cap, 0, 1)
 *   transaction_activity 100 · t / (t + txns_reference_count)
 *
 * When a component's underlying metric is missing/unusable, that component is
 * set to `unavailable_component_score` (a reduced value) rather than 0 or 50.
 */
class TrendScorer
{
    /** @var array{price_momentum:float,volume_liquidity:float,peak_retention:float,transaction_activity:float} */
    private array $weights;

    private float $momentumReferencePct;

    private float $volumeLiquidityReferenceRatio;

    private float $txnsReferenceCount;

    private float $unavailableScore;

    public function __construct()
    {
        $this->weights = [
            'price_momentum' => (float) config('trend.weights.price_momentum'),
            'volume_liquidity' => (float) config('trend.weights.volume_liquidity'),
            'peak_retention' => (float) config('trend.weights.peak_retention'),
            'transaction_activity' => (float) config('trend.weights.transaction_activity'),
        ];
        $this->momentumReferencePct = max(1e-9, (float) config('trend.momentum_reference_pct'));
        $this->volumeLiquidityReferenceRatio = max(1e-9, (float) config('trend.volume_liquidity_reference_ratio'));
        $this->txnsReferenceCount = max(1e-9, (float) config('trend.txns_reference_count'));
        $this->unavailableScore = (float) config('trend.unavailable_component_score');
    }

    public function forToken(Token $token): TrendScore
    {
        return $this->score(TrendInputs::fromToken($token));
    }

    public function score(TrendInputs $inputs): TrendScore
    {
        $components = [
            'price_momentum' => $this->priceMomentum($inputs->priceChangeH24),
            'volume_liquidity' => $this->volumeLiquidity($inputs->volumeH24, $inputs->liquidityUsd),
            'peak_retention' => $this->peakRetention($inputs->currentMarketCap, $inputs->observedPeakMarketCap),
            'transaction_activity' => $this->transactionActivity($inputs->txnsH24),
        ];

        $weightSum = array_sum($this->weights);
        $weighted = 0.0;

        foreach ($components as $name => $value) {
            $weighted += $this->weights[$name] * $value;
        }

        $overall = $weightSum > 0.0 ? $weighted / $weightSum : 0.0;

        return new TrendScore(
            score: round($this->clamp($overall), 1),
            components: array_map(fn (float $v): float => round($v, 1), $components),
            inputs: $inputs,
        );
    }

    private function priceMomentum(?float $priceChangePct): float
    {
        if ($priceChangePct === null) {
            return $this->unavailableScore;
        }

        return $this->clamp(50.0 * (1.0 + tanh($priceChangePct / $this->momentumReferencePct)));
    }

    private function volumeLiquidity(?float $volume, ?float $liquidity): float
    {
        if ($volume === null || $liquidity === null || $liquidity <= 0.0) {
            return $this->unavailableScore;
        }

        $ratio = max(0.0, $volume) / $liquidity;

        return $this->clamp(100.0 * $ratio / ($ratio + $this->volumeLiquidityReferenceRatio));
    }

    private function peakRetention(?float $currentMarketCap, ?float $observedPeak): float
    {
        if ($currentMarketCap === null || $observedPeak === null || $observedPeak <= 0.0) {
            return $this->unavailableScore;
        }

        // current MC should never exceed the observed peak; clamp if the data is inconsistent.
        $ratio = min(1.0, max(0.0, $currentMarketCap / $observedPeak));

        return $this->clamp(100.0 * $ratio);
    }

    private function transactionActivity(?int $txns): float
    {
        if ($txns === null) {
            return $this->unavailableScore;
        }

        $t = max(0.0, (float) $txns);

        return $this->clamp(100.0 * $t / ($t + $this->txnsReferenceCount));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
