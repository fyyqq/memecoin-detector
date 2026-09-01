<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic numeric pump-dump shape analysis (Step 24).
 *
 * Reads ONLY our stored `market_snapshots` (chronological, ~10-min cadence) plus
 * any `pump_events`. No external calls, no chart images.
 */
class ChartShapeAnalyzer
{
    /**
     * @param  Collection<int, MarketSnapshot>  $snapshots  newest-first or oldest-first — sorted here
     * @param  Collection<int, PumpEvent>  $pumpEvents
     */
    public function analyze(Collection $snapshots, Collection $pumpEvents, CarbonImmutable $now): ChartShape
    {
        $minSnapshots = (int) config('risk.pump_dump.min_snapshots', 6);
        $windowHours = (int) config('risk.pump_dump.window_hours', 6);
        $runupThreshold = (float) config('risk.pump_dump.round_trip_runup', 1.0);
        $retraceThreshold = (float) config('risk.pump_dump.round_trip_retrace', 0.60);
        $volumeCollapseAt = (float) config('risk.pump_dump.volume_collapse_at', 0.20);

        /** @var list<MarketSnapshot> $series */
        $series = $snapshots
            ->filter(fn (MarketSnapshot $s): bool => $s->market_cap !== null && $s->market_cap > 0.0 && $s->observed_at !== null)
            ->sortBy(fn (MarketSnapshot $s): int => $s->observed_at->getTimestamp())
            ->values()
            ->all();

        if (count($series) < $minSnapshots) {
            return ChartShape::insufficient(count($series));
        }

        $caps = array_map(fn (MarketSnapshot $s): float => (float) $s->market_cap, $series);
        $times = array_map(fn (MarketSnapshot $s): int => $s->observed_at->getTimestamp(), $series);

        // Peak (highest MC) and the current (latest) MC.
        $peakIndex = 0;
        foreach ($caps as $i => $c) {
            if ($c > $caps[$peakIndex]) {
                $peakIndex = $i;
            }
        }
        $peakCap = $caps[$peakIndex];
        $currentCap = $caps[count($caps) - 1];

        $peakToCurrentDrawdown = $peakCap > 0.0 ? max(0.0, ($peakCap - $currentCap) / $peakCap) : null;
        $timeSincePeakHours = ($now->getTimestamp() - $times[$peakIndex]) / 3600.0;

        // Max run-up / max drawdown within any rolling window.
        [$maxRunup, $maxDrawdown] = $this->rollingExtremes($caps, $times, $windowHours * 3600);

        // Round trip: a run-up from the pre-peak trough to the peak of at least
        // `runupThreshold`, then a retrace of at least `retraceThreshold` of that
        // gain by the current observation.
        $preTrough = $peakCap;
        for ($i = 0; $i <= $peakIndex; $i++) {
            $preTrough = min($preTrough, $caps[$i]);
        }
        $runup = $preTrough > 0.0 ? ($peakCap - $preTrough) / $preTrough : 0.0;
        $gain = $peakCap - $preTrough;
        $retraceFraction = $gain > 0.0 ? ($peakCap - $currentCap) / $gain : 0.0;
        $roundTrip = $runup >= $runupThreshold && $retraceFraction >= $retraceThreshold;

        // Volume collapse: latest 24h volume well below the volume at the peak.
        $peakVolume = $series[$peakIndex]->volume_h24;
        $currentVolume = $series[count($series) - 1]->volume_h24;
        $volumeCollapse = $peakVolume !== null && $peakVolume > 0.0
            && $currentVolume !== null
            && $currentVolume < $peakVolume * $volumeCollapseAt;

        // Severe short-duration pump followed by collapse — a completed pump
        // event with a large move and a large subsequent drawdown.
        $severe = false;
        foreach ($pumpEvents as $event) {
            if ($event->status === PumpEvent::STATUS_COMPLETED
                && $event->market_cap_change_pct !== null
                && $event->market_cap_change_pct >= 100.0
                && $peakToCurrentDrawdown !== null
                && $peakToCurrentDrawdown >= $retraceThreshold) {
                $severe = true;
                break;
            }
        }

        return new ChartShape(
            sufficientHistory: true,
            peakToCurrentDrawdownPct: $peakToCurrentDrawdown !== null ? round($peakToCurrentDrawdown * 100, 2) : null,
            maxRunupPct: $maxRunup !== null ? round($maxRunup * 100, 2) : null,
            maxDrawdownPct: $maxDrawdown !== null ? round($maxDrawdown * 100, 2) : null,
            timeSincePeakHours: round($timeSincePeakHours, 2),
            roundTrip: $roundTrip,
            volumeCollapse: $volumeCollapse,
            severeShortPumpThenCollapse: $severe,
            snapshotsConsidered: count($series),
        );
    }

    /**
     * Largest low->high rise and largest high->low fall within any rolling
     * window of `$windowSeconds`.
     *
     * @param  list<float>  $caps
     * @param  list<int>  $times
     * @return array{0:float|null,1:float|null}
     */
    private function rollingExtremes(array $caps, array $times, int $windowSeconds): array
    {
        $maxRunup = null;
        $maxDrawdown = null;
        $n = count($caps);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($windowSeconds < $times[$j] - $times[$i]) {
                    break;
                }
                if ($caps[$i] > 0.0) {
                    $rise = ($caps[$j] - $caps[$i]) / $caps[$i];
                    if ($rise > 0.0 && ($maxRunup === null || $rise > $maxRunup)) {
                        $maxRunup = $rise;
                    }
                }
                if ($caps[$i] > 0.0) {
                    $fall = ($caps[$i] - $caps[$j]) / $caps[$i];
                    if ($fall > 0.0 && ($maxDrawdown === null || $fall > $maxDrawdown)) {
                        $maxDrawdown = $fall;
                    }
                }
            }
        }

        return [$maxRunup, $maxDrawdown];
    }
}
