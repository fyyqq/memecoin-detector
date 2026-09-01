<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Evaluates ONE token's performance for ONE calendar month — deterministically.
 *
 * All figures come from OBSERVED / VERIFIED market cap in the month's
 * `MarketSnapshot`s only. FDV, historical estimates and external estimates are
 * NEVER used for monthly-championship scoring.
 *
 * Score = 100 * ( w_growth   * growth_score
 *               + w_expansion * expansion_score
 *               + w_activity  * activity_score ), each sub-score in [0, 1],
 * normalized with a deterministic capped-log so extreme outliers do not
 * dominate. See docs/monthly-rankings.md for the exact formulas.
 *
 * The score is NOT a prediction of future returns.
 */
class MonthlyPerformanceCalculator
{
    /**
     * @param  Collection<int, MarketSnapshot>  $monthSnapshots  the token's snapshots whose
     *                                                           `observed_at` falls inside `$window`
     */
    public function evaluate(
        Token $token,
        Collection $monthSnapshots,
        MonthWindow $window,
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
            baselineMarketCap: null,
            peakMarketCap: null,
            marketCapGrowthPct: null,
            peakExpansionRatio: null,
            activityScore: null,
            observationCount: 0,
            observationCoverageRatio: null,
            performanceScore: null,
            breakdown: ['reason' => $reason],
        );

        // 1. Token belongs to the eligible trending universe (Step 19 rule):
        //    a VERIFIED / OBSERVED market cap peak in [$5M, $200M].
        //    HISTORICAL_ESTIMATE and UNKNOWN never qualify.
        if ($token->earliest_pair_created_at === null) {
            return $ineligible('no_pool_creation_timestamp');
        }
        if (! $this->tokenInEligibleUniverse($token, $min, $max)) {
            return $ineligible('token_not_in_5m_200m_universe');
        }

        // 2. A token that reached > $200M AT ANY POINT in the month is out —
        //    even if it later fell back.
        $anyAboveCeiling = $monthSnapshots->contains(
            fn (MarketSnapshot $s): bool => $s->market_cap !== null && $s->market_cap > $max,
        );
        if ($anyAboveCeiling) {
            return $ineligible('exceeded_200m_ceiling_in_month');
        }

        // 3. Eligible snapshots for this month: real MC, in band, age <= 30d,
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

        // 4. Baseline = earliest eligible snapshot IN THE MONTH.
        $baseline = (float) $eligible->first()->market_cap;
        if ($baseline <= 0.0) {
            return $ineligible('invalid_baseline');
        }

        // 5. Peak = highest eligible snapshot MC in the month.
        $peak = (float) $eligible->max('market_cap');
        if ($peak < $min) {
            return $ineligible('month_peak_below_5m');
        }

        // 6. Growth + expansion.
        $growthPct = ($peak - $baseline) / $baseline * 100.0;
        $expansionRatio = $peak / $baseline;

        // 7. Observation coverage over the token's POSSIBLE in-month window.
        $intervalMinutes = max(1, (int) ($cfg['observation_interval_minutes'] ?? 10));
        $windowStart = $this->maxDate([
            $window->start,
            $token->first_observed_at,
            $token->earliest_pair_created_at,
        ]);
        $windowEnd = $this->minDate([
            $window->endExclusive,
            $ageCutoff,
            $now,
        ]);
        $expected = 1;
        if ($windowEnd->greaterThan($windowStart)) {
            $minutes = ($windowEnd->getTimestamp() - $windowStart->getTimestamp()) / 60.0;
            $expected = max(1, (int) floor($minutes / $intervalMinutes));
        }
        $observationCount = $eligible->count();
        $coverage = min(1.0, $observationCount / $expected);

        // 8. Activity (supporting evidence only).
        $activityUnit = $this->activityScore($eligible, (array) ($cfg['activity'] ?? []));

        // 9. Score.
        $growthScore = $this->normLog($growthPct / 100.0, (float) ($cfg['growth_reference'] ?? 20.0));
        $expansionScore = $this->expansionScore($expansionRatio, (float) ($cfg['expansion_reference'] ?? 25.0));

        $w = (array) ($cfg['weights'] ?? []);
        $wg = (float) ($w['growth'] ?? 0.60);
        $we = (float) ($w['expansion'] ?? 0.25);
        $wa = (float) ($w['activity'] ?? 0.15);

        $score = round(100.0 * max(0.0, min(1.0, $wg * $growthScore + $we * $expansionScore + $wa * $activityUnit)), 2);

        $minCoverage = (float) ($cfg['min_observation_coverage'] ?? 0.25);
        $status = $coverage < $minCoverage
            ? MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION
            : MonthlyCandidate::STATUS_ELIGIBLE;

        return new MonthlyCandidate(
            token: $token,
            status: $status,
            ineligibleReason: null,
            baselineMarketCap: round($baseline, 2),
            peakMarketCap: round($peak, 2),
            marketCapGrowthPct: round($growthPct, 2),
            peakExpansionRatio: round($expansionRatio, 4),
            activityScore: round($activityUnit * 100.0, 2),
            observationCount: $observationCount,
            observationCoverageRatio: round($coverage, 4),
            performanceScore: $score,
            breakdown: [
                'weights' => ['growth' => $wg, 'expansion' => $we, 'activity' => $wa],
                'growth_score' => round($growthScore, 4),
                'expansion_score' => round($expansionScore, 4),
                'activity_score' => round($activityUnit, 4),
                'growth_reference' => (float) ($cfg['growth_reference'] ?? 20.0),
                'expansion_reference' => (float) ($cfg['expansion_reference'] ?? 25.0),
                'expected_observations' => $expected,
                'min_observation_coverage' => $minCoverage,
            ],
        );
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

        // Never re-admit a token whose verified/observed peak EVER exceeded $200M.
        $greatestPeak = max(
            (float) ($token->observed_peak_market_cap ?? 0.0),
            (float) ($token->historical_peak_value ?? 0.0),
        );

        // A stored HISTORICAL_ESTIMATE / UNKNOWN status never satisfies the
        // universe on its own (handled by qualifies() + the observed path).
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

        $volume = $this->normLog(
            $this->median($eligible->pluck('volume_h24')),
            (float) ($activityCfg['volume_reference'] ?? 500_000),
        );
        $liquidity = $this->normLog(
            $this->median($eligible->pluck('liquidity_usd')),
            (float) ($activityCfg['liquidity_reference'] ?? 250_000),
        );
        $txns = $this->normLog(
            $this->median($eligible->pluck('txns_h24')),
            (float) ($activityCfg['txns_reference'] ?? 2_000),
        );
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

    /**
     * Score a HISTORICALLY-RESEARCHED candidate from external market-cap figures
     * — the SAME deterministic formula as {@see evaluate()}, but the inputs come
     * from research evidence instead of our snapshots. Used by
     * {@see MonthlyChampionResearchService}.
     *
     * Growth needs both baseline + peak; with only a peak the score is null
     * (we never rank by market-cap size). Activity is a volume-only proxy.
     *
     * @return array{performance_score:?float,market_cap_growth_pct:?float,peak_expansion_ratio:?float,activity_score:?float,breakdown:array<string,mixed>}
     */
    public function scoreHistorical(?float $baseline, ?float $peak, ?float $volumeUsd): array
    {
        $cfg = (array) config('ranking');
        $w = (array) ($cfg['weights'] ?? []);
        $wg = (float) ($w['growth'] ?? 0.60);
        $we = (float) ($w['expansion'] ?? 0.25);
        $wa = (float) ($w['activity'] ?? 0.15);

        $activityRef = (float) (($cfg['activity']['volume_reference'] ?? 500_000));
        $activityUnit = $volumeUsd !== null && $volumeUsd > 0.0
            ? $this->normLog($volumeUsd, $activityRef)
            : 0.0;

        if ($baseline === null || $baseline <= 0.0 || $peak === null || $peak <= 0.0) {
            return [
                'performance_score' => null,
                'market_cap_growth_pct' => null,
                'peak_expansion_ratio' => null,
                'activity_score' => round($activityUnit * 100.0, 2),
                'breakdown' => ['reason' => 'incomplete_market_cap_figures', 'activity_score' => round($activityUnit, 4)],
            ];
        }

        $growthPct = ($peak - $baseline) / $baseline * 100.0;
        $expansionRatio = $peak / $baseline;

        $growthScore = $this->normLog($growthPct / 100.0, (float) ($cfg['growth_reference'] ?? 20.0));
        $expansionScore = $this->expansionScore($expansionRatio, (float) ($cfg['expansion_reference'] ?? 25.0));

        $score = round(100.0 * max(0.0, min(1.0, $wg * $growthScore + $we * $expansionScore + $wa * $activityUnit)), 2);

        return [
            'performance_score' => $score,
            'market_cap_growth_pct' => round($growthPct, 2),
            'peak_expansion_ratio' => round($expansionRatio, 4),
            'activity_score' => round($activityUnit * 100.0, 2),
            'breakdown' => [
                'weights' => ['growth' => $wg, 'expansion' => $we, 'activity' => $wa],
                'growth_score' => round($growthScore, 4),
                'expansion_score' => round($expansionScore, 4),
                'activity_score' => round($activityUnit, 4),
                'basis' => 'historical_research_market_cap',
            ],
        ];
    }

    /**
     * Deterministic capped-log normalization to [0, 1]:
     *   min(1, ln(1 + x) / ln(1 + reference))
     */
    private function normLog(float $value, float $reference): float
    {
        $value = max(0.0, $value);
        if ($reference <= 0.0) {
            return 0.0;
        }

        return min(1.0, log(1.0 + $value) / log(1.0 + $reference));
    }

    private function expansionScore(float $ratio, float $reference): float
    {
        $ratio = max(1.0, $ratio);
        if ($reference <= 1.0) {
            return $ratio > 1.0 ? 1.0 : 0.0;
        }

        return min(1.0, log($ratio) / log($reference));
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
