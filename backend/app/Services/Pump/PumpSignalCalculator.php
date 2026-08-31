<?php

declare(strict_types=1);

namespace App\Services\Pump;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;

/**
 * Pure, deterministic pump signal maths — no DB, no time, no randomness.
 *
 * Given two observations (start → peak) it computes the four raw signals, a
 * 0-100 STRENGTH score, and a confidence label. All rules come from
 * config/pump.php and are documented there.
 */
class PumpSignalCalculator
{
    /** @var array<string,float> */
    private array $thresholds;

    /** @var array<string,float> */
    private array $score;

    public function __construct()
    {
        /** @var array<string,float> $t */
        $t = config('pump.thresholds');
        $this->thresholds = $t;
        /** @var array<string,float> $s */
        $s = config('pump.score');
        $this->score = $s;
    }

    public function signals(MarketSnapshot $start, MarketSnapshot $peak): PumpSignals
    {
        return new PumpSignals(
            marketCapChangePct: $this->pct($start->market_cap, $peak->market_cap),
            priceChangePct: $this->pct($start->price_usd, $peak->price_usd),
            volumeH24ChangeRatio: $this->ratio($start->volume_h24, $peak->volume_h24),
            txnsH24ChangeRatio: $this->ratio($this->txns($start), $this->txns($peak)),
        );
    }

    /** Does at least one of market-cap / price clear its "significant move" threshold? */
    public function hasSignificantMove(PumpSignals $s): bool
    {
        return ($s->marketCapChangePct !== null && $s->marketCapChangePct >= $this->thresholds['minimum_market_cap_change_pct'])
            || ($s->priceChangePct !== null && $s->priceChangePct >= $this->thresholds['minimum_price_change_pct']);
    }

    /** How many of the four signals clear their threshold (0-4). */
    public function passingSignalCount(PumpSignals $s): int
    {
        $t = $this->thresholds;

        return (int) ($s->marketCapChangePct !== null && $s->marketCapChangePct >= $t['minimum_market_cap_change_pct'])
            + (int) ($s->priceChangePct !== null && $s->priceChangePct >= $t['minimum_price_change_pct'])
            + (int) ($s->volumeH24ChangeRatio !== null && $s->volumeH24ChangeRatio >= $t['minimum_volume_change_ratio'])
            + (int) ($s->txnsH24ChangeRatio !== null && $s->txnsH24ChangeRatio >= $t['minimum_transaction_change_ratio']);
    }

    /**
     * Deterministic 0-100 strength score. Weights sum to 100; each component
     * saturates at 2x its threshold. `$accelerationMarketCapChangePct` (the
     * short-window move) adds a small bonus for rapid recent acceleration.
     */
    public function score(PumpSignals $s, ?float $accelerationMarketCapChangePct = null): int
    {
        $t = $this->thresholds;

        $raw = $this->component($s->marketCapChangePct, $t['minimum_market_cap_change_pct'], $this->score['weight_market_cap'])
            + $this->component($s->priceChangePct, $t['minimum_price_change_pct'], $this->score['weight_price'])
            + $this->component($this->ratioAsPct($s->volumeH24ChangeRatio), $this->ratioAsPct($t['minimum_volume_change_ratio']), $this->score['weight_volume'])
            + $this->component($this->ratioAsPct($s->txnsH24ChangeRatio), $this->ratioAsPct($t['minimum_transaction_change_ratio']), $this->score['weight_transactions']);

        if ($accelerationMarketCapChangePct !== null && $accelerationMarketCapChangePct > 0 && $t['minimum_market_cap_change_pct'] > 0) {
            $bonusMax = $this->score['acceleration_bonus_max'];
            $raw += min($bonusMax, $accelerationMarketCapChangePct / $t['minimum_market_cap_change_pct'] * $bonusMax);
        }

        return (int) round(max(0.0, min(100.0, $raw)));
    }

    /**
     * Deterministic confidence (see config/pump.php):
     *   HIGH   — a STRONG move (>= strong_move_multiplier x threshold) AND both
     *            the volume and transaction ratios confirm.
     *   MEDIUM — a move clears its threshold AND exactly one activity ratio confirms.
     *   LOW    — detected, but weak: e.g. market-cap + price only, no activity
     *            confirmation, or a marginal move.
     */
    public function confidence(PumpSignals $s): string
    {
        $t = $this->thresholds;
        $mult = $t['strong_move_multiplier'];

        $strongMove = ($s->marketCapChangePct !== null && $s->marketCapChangePct >= $t['minimum_market_cap_change_pct'] * $mult)
            || ($s->priceChangePct !== null && $s->priceChangePct >= $t['minimum_price_change_pct'] * $mult);

        $volumeOk = $s->volumeH24ChangeRatio !== null && $s->volumeH24ChangeRatio >= $t['minimum_volume_change_ratio'];
        $txnsOk = $s->txnsH24ChangeRatio !== null && $s->txnsH24ChangeRatio >= $t['minimum_transaction_change_ratio'];
        $confirmations = (int) $volumeOk + (int) $txnsOk;

        if ($strongMove && $confirmations >= 2) {
            return PumpEvent::CONFIDENCE_HIGH;
        }

        if ($this->hasSignificantMove($s) && $confirmations >= 1) {
            return PumpEvent::CONFIDENCE_MEDIUM;
        }

        return PumpEvent::CONFIDENCE_LOW;
    }

    public function pct(?float $from, ?float $to): ?float
    {
        if ($from === null || $to === null || $from <= 0.0) {
            return null;
        }

        return ($to - $from) / $from * 100.0;
    }

    private function ratio(?float $from, ?float $to): ?float
    {
        if ($from === null || $to === null || $from <= 0.0) {
            return null;
        }

        return $to / $from;
    }

    private function ratioAsPct(?float $ratio): ?float
    {
        return $ratio === null ? null : ($ratio - 1.0) * 100.0;
    }

    private function component(?float $value, float $threshold, float $weight): float
    {
        if ($value === null || $value <= 0.0 || $threshold <= 0.0) {
            return 0.0;
        }

        return $weight * min(1.0, $value / ($threshold * 2.0));
    }

    private function txns(MarketSnapshot $s): ?float
    {
        if ($s->txns_h24 !== null) {
            return (float) $s->txns_h24;
        }

        if ($s->buys_h24 === null && $s->sells_h24 === null) {
            return null;
        }

        return (float) (($s->buys_h24 ?? 0) + ($s->sells_h24 ?? 0));
    }
}
