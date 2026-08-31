<?php

declare(strict_types=1);

namespace App\Services\Pump;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use Carbon\CarbonImmutable;

/**
 * Finds an observed pump in ONE token's recent observation series.
 *
 * Deterministic, pure (no DB writes, no HTTP). Operates only on the snapshot
 * rows handed to it. Timestamps returned are snapshot `observed_at` values — an
 * "observed pump", never exact tick boundaries.
 */
class PumpDetector
{
    private int $primaryMinutes;

    private int $accelerationMinutes;

    private int $toleranceMinutes;

    private int $minimumConfirmations;

    public function __construct(private readonly PumpSignalCalculator $calc)
    {
        $this->primaryMinutes = (int) config('pump.windows.primary_minutes');
        $this->accelerationMinutes = (int) config('pump.windows.acceleration_minutes');
        $this->toleranceMinutes = (int) config('pump.windows.tolerance_minutes');
        $this->minimumConfirmations = (int) config('pump.thresholds.minimum_confirmation_signals');
    }

    /**
     * @param  list<MarketSnapshot>  $snapshots  ordered by observed_at ASC (oldest first)
     */
    public function detect(array $snapshots): ?PumpDetection
    {
        $count = count($snapshots);
        if ($count < 2) {
            return null;
        }

        $latest = $snapshots[$count - 1];
        $latestAt = $latest->observed_at;
        if (! $latestAt instanceof CarbonImmutable) {
            return null;
        }

        // A comparison baseline must be old enough for a meaningful window but
        // not so old the interval no longer resembles the primary window.
        $minAge = $this->accelerationMinutes;
        $maxAge = $this->primaryMinutes + $this->toleranceMinutes;

        $candidates = array_values(array_filter($snapshots, function (MarketSnapshot $s) use ($latest, $latestAt, $minAge, $maxAge): bool {
            if ($s->id === $latest->id || ! $s->observed_at instanceof CarbonImmutable) {
                return false;
            }
            $age = $s->observed_at->diffInMinutes($latestAt);

            return $age >= $minAge && $age <= $maxAge;
        }));

        if ($candidates === []) {
            return null;
        }

        $windowStart = $this->closestTo($candidates, $latestAt->subMinutes($this->primaryMinutes));

        // The candidate window: windowStart .. latest.
        $window = array_values(array_filter(
            $snapshots,
            fn (MarketSnapshot $s): bool => $s->observed_at instanceof CarbonImmutable
                && $s->observed_at->greaterThanOrEqualTo($windowStart->observed_at),
        ));

        $peak = $this->highest($window) ?? $latest;

        // Start = the lowest observation at or before the peak (the trough the
        // move rose from), never after it.
        $preOrAtPeak = array_values(array_filter(
            $window,
            fn (MarketSnapshot $s): bool => $s->observed_at instanceof CarbonImmutable
                && $s->observed_at->lessThanOrEqualTo($peak->observed_at),
        ));
        $start = $this->lowest($preOrAtPeak) ?? $windowStart;

        if (! $start->observed_at instanceof CarbonImmutable
            || ! $peak->observed_at instanceof CarbonImmutable
            || $start->observed_at->greaterThanOrEqualTo($peak->observed_at)) {
            return null; // no rise inside the window
        }

        $signals = $this->calc->signals($start, $peak);

        if (! $this->calc->hasSignificantMove($signals)
            || $this->calc->passingSignalCount($signals) < $this->minimumConfirmations) {
            return null;
        }

        // Acceleration: latest vs ~accelerationMinutes ago, if that baseline sits
        // inside the pump. Score bonus only.
        $accelMcPct = null;
        $accelBaseline = $this->closestTo(
            array_values(array_filter(
                $window,
                fn (MarketSnapshot $s): bool => $s->id !== $latest->id
                    && $s->observed_at instanceof CarbonImmutable
                    && $s->observed_at->greaterThanOrEqualTo($start->observed_at),
            )),
            $latestAt->subMinutes($this->accelerationMinutes),
        );
        if ($accelBaseline !== null && $accelBaseline->id !== $latest->id) {
            $accelMcPct = $this->calc->pct($accelBaseline->market_cap, $latest->market_cap);
        }

        $score = $this->calc->score($signals, $accelMcPct);
        $confidence = $this->calc->confidence($signals);

        $peakIsLatest = $peak->id === $latest->id;
        $endedAt = $peakIsLatest ? null : $latestAt;
        $status = $peakIsLatest ? PumpEvent::STATUS_ACTIVE : PumpEvent::STATUS_COMPLETED;

        return new PumpDetection(
            startedAt: $start->observed_at,
            peakAt: $peak->observed_at,
            endedAt: $endedAt,
            startMarketCap: $start->market_cap,
            peakMarketCap: $peak->market_cap,
            startPriceUsd: $start->price_usd,
            peakPriceUsd: $peak->price_usd,
            marketCapChangePct: $signals->marketCapChangePct,
            priceChangePct: $signals->priceChangePct,
            volumeH24ChangeRatio: $signals->volumeH24ChangeRatio,
            txnsH24ChangeRatio: $signals->txnsH24ChangeRatio,
            durationMinutes: (int) round($start->observed_at->diffInMinutes($peak->observed_at)),
            detectionScore: $score,
            confidence: $confidence,
            status: $status,
        );
    }

    /**
     * @param  list<MarketSnapshot>  $snapshots  non-empty
     */
    private function closestTo(array $snapshots, CarbonImmutable $target): MarketSnapshot
    {
        $best = $snapshots[0];
        $bestDelta = abs($best->observed_at->getTimestamp() - $target->getTimestamp());

        foreach ($snapshots as $s) {
            $delta = abs($s->observed_at->getTimestamp() - $target->getTimestamp());
            // Deterministic tie-break: earlier observation wins.
            if ($delta < $bestDelta || ($delta === $bestDelta && $s->observed_at->lessThan($best->observed_at))) {
                $best = $s;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    /**
     * Highest observation by market cap (fallback: price). Null if the list is empty.
     *
     * @param  list<MarketSnapshot>  $snapshots
     */
    private function highest(array $snapshots): ?MarketSnapshot
    {
        return $this->pick($snapshots, fn (float $a, float $b): bool => $a > $b);
    }

    /**
     * Lowest observation by market cap (fallback: price). Null if the list is empty.
     *
     * @param  list<MarketSnapshot>  $snapshots
     */
    private function lowest(array $snapshots): ?MarketSnapshot
    {
        return $this->pick($snapshots, fn (float $a, float $b): bool => $a < $b);
    }

    /**
     * @param  list<MarketSnapshot>  $snapshots
     * @param  callable(float,float):bool  $isBetter
     */
    private function pick(array $snapshots, callable $isBetter): ?MarketSnapshot
    {
        $best = null;
        $bestValue = null;

        foreach ($snapshots as $s) {
            $value = $s->market_cap ?? $s->price_usd;
            if ($value === null) {
                continue;
            }
            if ($bestValue === null || $isBetter($value, $bestValue)
                || ($value === $bestValue && $best !== null && $s->observed_at->lessThan($best->observed_at))) {
                $best = $s;
                $bestValue = $value;
            }
        }

        return $best ?? ($snapshots[0] ?? null);
    }
}
