<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * Computes the transparent, deterministic `tracked_trend_score` (0-100) for one
 * token in one timeframe.
 *
 * Pure + deterministic: same inputs -> same score. No history lookups (the
 * caller supplies `appearances`), no network, no randomness, NO AI. All tuning
 * lives in config/trending.php.
 *
 *   tracked_trend_score = 100 * ( Σ weight_i · component_i ) / Σ weight_i
 *
 * Components (each clamped to 0..1):
 *   momentum              0.5 · (1 + tanh(price_change_pct / ref_momentum_pct))
 *   volume_activity       v / (v + ref_volume_usd)
 *   transaction_activity  t / (t + ref_txns)
 *   liquidity_quality     l / (l + ref_liquidity_usd)
 *   persistence           appearances / persistence_window
 *
 * MARKET CAP IS NOT AN INPUT. A missing/unusable metric -> `unavailable_component`
 * (a reduced value below 0.5) so incomplete data lowers the score.
 *
 * This is NOT DexScreener's proprietary `trendingScore` — see
 * docs/trending-tracking.md.
 */
class TrackedTrendScorer
{
    /** @var array{momentum:float,volume_activity:float,transaction_activity:float,liquidity_quality:float,persistence:float} */
    private array $weights;

    private float $momentumRefPct;

    private float $volumeRefUsd;

    private float $txnsRef;

    private float $liquidityRefUsd;

    private float $unavailable;

    public function __construct()
    {
        $this->weights = [
            'momentum' => (float) config('trending.score.weights.momentum'),
            'volume_activity' => (float) config('trending.score.weights.volume_activity'),
            'transaction_activity' => (float) config('trending.score.weights.transaction_activity'),
            'liquidity_quality' => (float) config('trending.score.weights.liquidity_quality'),
            'persistence' => (float) config('trending.score.weights.persistence'),
        ];
        $this->momentumRefPct = max(1e-9, (float) config('trending.score.references.momentum_pct'));
        $this->volumeRefUsd = max(1.0, (float) config('trending.score.references.volume_usd'));
        $this->txnsRef = max(1e-9, (float) config('trending.score.references.txns'));
        $this->liquidityRefUsd = max(1e-9, (float) config('trending.score.references.liquidity_usd'));
        $this->unavailable = (float) config('trending.score.unavailable_component');
    }

    public function score(TrackedTrendInputs $inputs): TrackedTrendScore
    {
        $components = [
            'momentum' => $this->momentum($inputs->priceChangePct),
            'volume_activity' => $this->volumeActivity($inputs->volumeUsd),
            'transaction_activity' => $this->transactionActivity($inputs->transactionCount),
            'liquidity_quality' => $this->liquidityQuality($inputs->liquidityUsd),
            'persistence' => $this->persistence($inputs->appearances, $inputs->persistenceWindow),
        ];

        $weightSum = array_sum($this->weights);
        $weighted = 0.0;
        foreach ($components as $name => $value) {
            $weighted += $this->weights[$name] * $value;
        }
        $overall = $weightSum > 0.0 ? $weighted / $weightSum : 0.0;

        return new TrackedTrendScore(
            score: round($this->clamp01($overall) * 100.0, 2),
            components: array_map(fn (float $v): float => round($this->clamp01($v) * 100.0, 2), $components),
            inputs: $inputs,
        );
    }

    private function momentum(?float $priceChangePct): float
    {
        if ($priceChangePct === null) {
            return $this->unavailable;
        }

        return $this->clamp01(0.5 * (1.0 + tanh($priceChangePct / $this->momentumRefPct)));
    }

    private function volumeActivity(?float $volumeUsd): float
    {
        if ($volumeUsd === null || $volumeUsd < 0.0) {
            return $this->unavailable;
        }

        return $this->clamp01($volumeUsd / ($volumeUsd + $this->volumeRefUsd));
    }

    private function transactionActivity(?int $txns): float
    {
        if ($txns === null) {
            return $this->unavailable;
        }

        $t = max(0.0, (float) $txns);

        return $this->clamp01($t / ($t + $this->txnsRef));
    }

    private function liquidityQuality(?float $liquidityUsd): float
    {
        if ($liquidityUsd === null || $liquidityUsd <= 0.0) {
            return $this->unavailable;
        }

        return $this->clamp01($liquidityUsd / ($liquidityUsd + $this->liquidityRefUsd));
    }

    private function persistence(int $appearances, int $window): float
    {
        if ($window <= 0) {
            return $this->unavailable;
        }

        return $this->clamp01($appearances / $window);
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
