<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Evaluates ONE token's monthly performance for the "Monthly Top Memecoins"
 * Top-3 (Step 25) — deterministically, from OBSERVED / VERIFIED data only.
 *
 * The selection score rewards real PARTICIPATION:
 *
 *   strength(x, ref) = min(1, ln(1 + x) / ln(1 + ref))          (capped-log)
 *
 *   holder_strength     = strength(holder_count,       ref.holder_count)
 *   volume_strength     = strength(monthly_volume_usd, ref.volume_usd)
 *   market_cap_strength = strength(month_peak_mc,      ref.market_cap_usd)
 *
 *   score = 100 · Σ(w · strength) / Σ(w)     over the KNOWN components
 *
 * default weights holder 0.40 / volume 0.35 / market_cap 0.25 (env-configurable,
 * `config/ranking.php`). A `null` holder_count is UNKNOWN — it drops out of the
 * sum and the remaining weights renormalize (it is never silently treated as 0).
 * Market cap is SUPPORTING — a $150M token does NOT automatically beat a $20M
 * token with far stronger holders + volume.
 *
 * FDV, `HISTORICAL_ESTIMATE`, external estimates, the Risk Assessment, AI and
 * social sentiment are NEVER used. `market_cap_growth_pct` / `peak_expansion_ratio`
 * / `activity_score` are still computed but are INFO-ONLY context.
 */
class MonthlyPerformanceCalculator
{
    /**
     * @param  Collection<int, MarketSnapshot>  $monthSnapshots  the token's snapshots whose
     *                                                           `observed_at` falls inside `$window`
     * @param  int|null  $holderCount  monthly-max holder count from the holder pass (null = UNKNOWN)
     */
    public function evaluate(
        Token $token,
        Collection $monthSnapshots,
        MonthWindow $window,
        ?int $holderCount,
        CarbonImmutable $now,
    ): MonthlyCandidate {
        $cfg = (array) config('ranking');
        $min = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $max = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');
        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');

        $ineligible = fn (string $reason): MonthlyCandidate => new MonthlyCandidate(
            token: $token,
            status: MonthlyCandidate::STATUS_INELIGIBLE,
            ineligibleReason: $reason,
            holderCount: null,
            monthlyVolumeUsd: null,
            monthMarketCap: null,
            holderStrength: null,
            volumeStrength: null,
            marketCapStrength: null,
            performanceScore: null,
            baselineMarketCap: null,
            peakMarketCap: null,
            marketCapGrowthPct: null,
            peakExpansionRatio: null,
            activityScore: null,
            observationCount: 0,
            observationCoverageRatio: null,
            breakdown: ['reason' => $reason],
        );

        // 1. Token belongs to the eligible universe (Step 19): a VERIFIED /
        //    OBSERVED market-cap peak in [$5M, $200M]. HISTORICAL_ESTIMATE and
        //    UNKNOWN never qualify.
        if ($token->earliest_pair_created_at === null) {
            return $ineligible('no_pool_creation_timestamp');
        }
        if (! $this->tokenInEligibleUniverse($token, $min, $max)) {
            return $ineligible('token_not_in_5m_200m_universe');
        }

        // 2. A token that reached > $200M AT ANY POINT in the month is out.
        $anyAboveCeiling = $monthSnapshots->contains(
            fn (MarketSnapshot $s): bool => $s->market_cap !== null && $s->market_cap > $max,
        );
        if ($anyAboveCeiling) {
            return $ineligible('exceeded_200m_ceiling_in_month');
        }

        // 3. Eligible snapshots for this month: real MC in band, age <= 30d,
        //    volume > 0, liquidity > 0.
        $ageCutoff = $token->earliest_pair_created_at->addDays($maxAgeDays);
        $eligible = $monthSnapshots
            ->filter(fn (MarketSnapshot $s): bool => $s->market_cap !== null
                && $s->market_cap > 0.0
                && $s->market_cap <= $max
                && $s->observed_at !== null
                && $s->observed_at->lessThanOrEqualTo($ageCutoff)
                && ($s->volume_h24 ?? 0.0) > 0.0
                && ($s->liquidity_usd ?? 0.0) > 0.0)
            ->sortBy(fn (MarketSnapshot $s): string => $s->observed_at->toIso8601String().':'.$s->id)
            ->values();

        if ($eligible->isEmpty()) {
            return $ineligible('no_eligible_snapshot_in_month');
        }

        // 4. Month peak = highest eligible MC in the month (the MC-strength basis).
        $baseline = (float) $eligible->first()->market_cap;
        $peak = (float) $eligible->max('market_cap');
        if ($peak < $min) {
            return $ineligible('month_peak_below_5m');
        }

        // 5. Representative monthly volume — MEDIAN in-month `volume_h24`. We do
        //    NOT sum rolling-24h samples (that double-counts).
        $monthlyVolume = $this->median($eligible->pluck('volume_h24'));
        if ($monthlyVolume <= 0.0) {
            return $ineligible('no_month_volume');
        }

        // 6. Info-only growth / expansion.
        $growthPct = $baseline > 0.0 ? ($peak - $baseline) / $baseline * 100.0 : null;
        $expansionRatio = $baseline > 0.0 ? $peak / $baseline : null;

        // 7. Observation coverage over the token's POSSIBLE in-month window.
        $intervalMinutes = max(1, (int) ($cfg['observation_interval_minutes'] ?? 10));
        $windowStart = $this->maxDate([$window->start, $token->first_observed_at, $token->earliest_pair_created_at]);
        $windowEnd = $this->minDate([$window->endExclusive, $ageCutoff, $now]);
        $expected = 1;
        if ($windowEnd->greaterThan($windowStart)) {
            $minutes = ($windowEnd->getTimestamp() - $windowStart->getTimestamp()) / 60.0;
            $expected = max(1, (int) floor($minutes / $intervalMinutes));
        }
        $observationCount = $eligible->count();
        $coverage = min(1.0, $observationCount / $expected);

        // 8. The three strengths + renormalized score.
        [$score, $holderStrength, $volumeStrength, $marketCapStrength, $weights] =
            $this->participationScore($holderCount, $monthlyVolume, $peak);

        $activityUnit = $this->activityScore($eligible, (array) ($cfg['activity'] ?? []));

        $minCoverage = (float) ($cfg['min_observation_coverage'] ?? 0.25);
        $status = $coverage < $minCoverage
            ? MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION
            : MonthlyCandidate::STATUS_ELIGIBLE;

        return new MonthlyCandidate(
            token: $token,
            status: $status,
            ineligibleReason: null,
            holderCount: $holderCount,
            monthlyVolumeUsd: round($monthlyVolume, 2),
            monthMarketCap: round($peak, 2),
            holderStrength: $holderStrength !== null ? round($holderStrength, 4) : null,
            volumeStrength: round($volumeStrength, 4),
            marketCapStrength: round($marketCapStrength, 4),
            performanceScore: $score,
            baselineMarketCap: round($baseline, 2),
            peakMarketCap: round($peak, 2),
            marketCapGrowthPct: $growthPct !== null ? round($growthPct, 2) : null,
            peakExpansionRatio: $expansionRatio !== null ? round($expansionRatio, 4) : null,
            activityScore: round($activityUnit * 100.0, 2),
            observationCount: $observationCount,
            observationCoverageRatio: round($coverage, 4),
            breakdown: [
                'method' => 'internal_observed',
                'weights' => $weights,
                'holder_count' => $holderCount,
                'holder_strength' => $holderStrength !== null ? round($holderStrength, 4) : null,
                'volume_strength' => round($volumeStrength, 4),
                'market_cap_strength' => round($marketCapStrength, 4),
                'monthly_volume_usd' => round($monthlyVolume, 2),
                'month_peak_market_cap' => round($peak, 2),
                'context' => [
                    'growth_pct' => $growthPct !== null ? round($growthPct, 2) : null,
                    'expansion_ratio' => $expansionRatio !== null ? round($expansionRatio, 4) : null,
                    'activity_score' => round($activityUnit, 4),
                ],
                'expected_observations' => $expected,
                'min_observation_coverage' => $minCoverage,
            ],
        );
    }

    /**
     * Score a HISTORICALLY-RESEARCHED candidate — the SAME participation formula
     * as {@see evaluate()}, from research evidence instead of our snapshots.
     * `null` holder count / volume are honestly UNKNOWN (the score renormalizes;
     * a candidate with no known volume AND no known market cap scores `null`).
     *
     * @return array{performance_score:?float,holder_strength:?float,volume_strength:?float,market_cap_strength:?float,market_cap_growth_pct:?float,peak_expansion_ratio:?float,breakdown:array<string,mixed>}
     */
    public function scoreHistorical(?float $baseline, ?float $monthPeakMc, ?float $volumeUsd, ?int $holderCount): array
    {
        $volume = $volumeUsd !== null && $volumeUsd > 0.0 ? $volumeUsd : null;

        if ($volume === null && ($monthPeakMc === null || $monthPeakMc <= 0.0)) {
            // Nothing to score by — never rank by holders alone or size alone.
            return [
                'performance_score' => null,
                'holder_strength' => null,
                'volume_strength' => null,
                'market_cap_strength' => null,
                'market_cap_growth_pct' => null,
                'peak_expansion_ratio' => null,
                'breakdown' => ['method' => 'historical_research', 'reason' => 'no_volume_or_market_cap'],
            ];
        }

        [$score, $holderStrength, $volumeStrength, $marketCapStrength, $weights] =
            $this->participationScore($holderCount, $volume ?? 0.0, $monthPeakMc ?? 0.0, allowMissingVolume: $volume === null, allowMissingMarketCap: $monthPeakMc === null || $monthPeakMc <= 0.0);

        // "Never rank by market-cap size alone." A researched candidate with only
        // a market cap (no holder count, no volume) is capped so it can still be
        // recorded but can never beat a candidate with real holder + volume
        // participation.
        $marketCapOnly = $holderCount === null && $volume === null && $monthPeakMc !== null && $monthPeakMc > 0.0;
        if ($marketCapOnly && $score !== null) {
            $penalty = max(0.0, min(1.0, (float) (config('ranking.market_cap_only_penalty', 0.5))));
            $score = round($score * $penalty, 2);
        }

        $growthPct = ($baseline !== null && $baseline > 0.0 && $monthPeakMc !== null && $monthPeakMc > 0.0)
            ? round(($monthPeakMc - $baseline) / $baseline * 100.0, 2) : null;
        $expansion = ($baseline !== null && $baseline > 0.0 && $monthPeakMc !== null && $monthPeakMc > 0.0)
            ? round($monthPeakMc / $baseline, 4) : null;

        return [
            'performance_score' => $score,
            'holder_strength' => $holderStrength !== null ? round($holderStrength, 4) : null,
            'volume_strength' => $volume !== null ? round($volumeStrength, 4) : null,
            'market_cap_strength' => ($monthPeakMc !== null && $monthPeakMc > 0.0) ? round($marketCapStrength, 4) : null,
            'market_cap_growth_pct' => $growthPct,
            'peak_expansion_ratio' => $expansion,
            'breakdown' => [
                'method' => 'historical_research',
                'weights' => $weights,
                'holder_count' => $holderCount,
                'monthly_volume_usd' => $volume,
                'month_peak_market_cap' => $monthPeakMc,
            ],
        ];
    }

    /**
     * `[score 0..100, holderStrength|null, volumeStrength, marketCapStrength, weightsUsed]`.
     * A component is dropped from the weighted mean when its input is unknown
     * (holder count null; or, for research, volume / market cap absent).
     *
     * @return array{0:?float,1:?float,2:float,3:float,4:array<string,float>}
     */
    private function participationScore(
        ?int $holderCount,
        float $volumeUsd,
        float $marketCapUsd,
        bool $allowMissingVolume = false,
        bool $allowMissingMarketCap = false,
    ): array {
        $cfg = (array) config('ranking');
        $w = (array) ($cfg['weights'] ?? []);
        $wh = (float) ($w['holder'] ?? 0.40);
        $wv = (float) ($w['volume'] ?? 0.35);
        $wmc = (float) ($w['market_cap'] ?? 0.25);
        $ref = (array) ($cfg['references'] ?? []);

        $holderStrength = $holderCount !== null && $holderCount > 0
            ? $this->normLog((float) $holderCount, (float) ($ref['holder_count'] ?? 10_000))
            : null;
        $volumeStrength = $this->normLog(max(0.0, $volumeUsd), (float) ($ref['volume_usd'] ?? 20_000_000));
        $marketCapStrength = $this->normLog(max(0.0, $marketCapUsd), (float) ($ref['market_cap_usd'] ?? 50_000_000));

        $num = 0.0;
        $den = 0.0;
        $used = [];
        if ($holderStrength !== null) {
            $num += $wh * $holderStrength;
            $den += $wh;
            $used['holder'] = $wh;
        }
        if (! $allowMissingVolume) {
            $num += $wv * $volumeStrength;
            $den += $wv;
            $used['volume'] = $wv;
        }
        if (! $allowMissingMarketCap) {
            $num += $wmc * $marketCapStrength;
            $den += $wmc;
            $used['market_cap'] = $wmc;
        }

        $score = $den > 0.0 ? round(100.0 * max(0.0, min(1.0, $num / $den)), 2) : null;

        return [$score, $holderStrength, $volumeStrength, $marketCapStrength, $used];
    }

    private function tokenInEligibleUniverse(Token $token, float $min, float $max): bool
    {
        $evidence = $token->historicalPeakEvidence;

        $viaEvidence = $evidence !== null && $evidence->qualifies($min, $max);
        $viaObserved = $token->observed_peak_market_cap !== null
            && $token->observed_peak_market_cap >= $min
            && $token->observed_peak_market_cap <= $max;

        if (! $viaEvidence && ! $viaObserved) {
            return false;
        }

        $greatestPeak = max(
            (float) ($token->observed_peak_market_cap ?? 0.0),
            (float) ($token->historical_peak_value ?? 0.0),
        );

        if (in_array($token->historical_peak_status, [
            HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
        ], true) && ! $viaObserved) {
            return false;
        }

        return $greatestPeak <= $max;
    }

    /**
     * @param  Collection<int, MarketSnapshot>  $eligible
     * @param  array<string,mixed>  $activityCfg
     */
    private function activityScore(Collection $eligible, array $activityCfg): float
    {
        $weights = (array) ($activityCfg['weights'] ?? []);

        $volume = $this->normLog($this->median($eligible->pluck('volume_h24')), (float) ($activityCfg['volume_reference'] ?? 500_000));
        $liquidity = $this->normLog($this->median($eligible->pluck('liquidity_usd')), (float) ($activityCfg['liquidity_reference'] ?? 250_000));
        $txns = $this->normLog($this->median($eligible->pluck('txns_h24')), (float) ($activityCfg['txns_reference'] ?? 2_000));
        $priceChange = $this->normLog(
            $this->median($eligible->map(fn (MarketSnapshot $s): float => abs((float) ($s->price_change_h24 ?? 0.0)))),
            (float) ($activityCfg['price_change_reference'] ?? 50),
        );

        return max(0.0, min(1.0,
            (float) ($weights['volume'] ?? 0.45) * $volume
            + (float) ($weights['liquidity'] ?? 0.30) * $liquidity
            + (float) ($weights['txns'] ?? 0.20) * $txns
            + (float) ($weights['price_change'] ?? 0.05) * $priceChange,
        ));
    }

    /** Deterministic capped-log normalization to [0, 1]: min(1, ln(1+x) / ln(1+reference)). */
    private function normLog(float $value, float $reference): float
    {
        $value = max(0.0, $value);
        if ($reference <= 0.0) {
            return 0.0;
        }

        return min(1.0, log(1.0 + $value) / log(1.0 + $reference));
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function median(Collection $values): float
    {
        $nums = $values
            ->map(fn ($v): float => (float) ($v ?? 0.0))
            ->filter(fn (float $v): bool => $v > 0.0)
            ->sort()
            ->values();

        if ($nums->isEmpty()) {
            return 0.0;
        }

        $count = $nums->count();
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $nums[$mid]
            : ((float) $nums[$mid - 1] + (float) $nums[$mid]) / 2.0;
    }

    /**
     * @param  list<CarbonImmutable|null>  $dates
     */
    private function maxDate(array $dates): CarbonImmutable
    {
        $out = null;
        foreach ($dates as $date) {
            if ($date !== null && ($out === null || $date->greaterThan($out))) {
                $out = $date;
            }
        }

        return $out ?? CarbonImmutable::now();
    }

    /**
     * @param  list<CarbonImmutable|null>  $dates
     */
    private function minDate(array $dates): CarbonImmutable
    {
        $out = null;
        foreach ($dates as $date) {
            if ($date !== null && ($out === null || $date->lessThan($out))) {
                $out = $date;
            }
        }

        return $out ?? CarbonImmutable::now();
    }
}
