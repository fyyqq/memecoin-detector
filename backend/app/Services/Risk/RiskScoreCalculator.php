<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskAssessment;
use App\Models\RiskSignal;

/**
 * Deterministic 0-100 risk score + level (Step 24).
 *
 * Higher score = MORE risk. This is a heuristic risk-screening score — NOT a
 * probability of scam / rug / loss.
 *
 *   score = 100 * Σ weight[group] * clamp(Σ signal contributions in group, 0, 1)
 *
 * Precedence for the final level:
 *   1. a CRITICAL / HIGH hard-override signal always wins (we measured
 *      something dangerous), and the triggering signal is recorded;
 *   2. otherwise, data completeness below the configured minimum, or a
 *      non-`completed` screening status, => RISK UNKNOWN (distinct from HIGH);
 *   3. otherwise the score band.
 *
 * UNKNOWN and NOT_AVAILABLE signals NEVER contribute to the score.
 */
class RiskScoreCalculator
{
    private const SEVERITY_RANK = [
        RiskSignal::SEVERITY_CRITICAL => 4,
        RiskSignal::SEVERITY_HIGH => 3,
        RiskSignal::SEVERITY_MEDIUM => 2,
        RiskSignal::SEVERITY_LOW => 1,
        RiskSignal::SEVERITY_NONE => 0,
    ];

    /**
     * @param  list<RiskSignalDraft>  $signals
     * @param  RiskAssessment::STATUS_*  $screeningStatus
     */
    public function calculate(array $signals, string $screeningStatus): RiskAssessmentResult
    {
        /** @var array<string,float> $weights */
        $weights = config('risk.score.weights', []);
        $levels = config('risk.score.levels', []);
        $minCompleteness = (float) config('risk.min_data_completeness', 0.5);

        // --- group scores ---
        $groupScores = [];
        foreach (array_keys($weights) as $group) {
            $sum = 0.0;
            foreach ($signals as $signal) {
                if ($signal->group !== $group || ! $signal->contributesToScore()) {
                    continue;
                }
                $sum += $signal->scoreContribution ?? 1.0;
            }
            $groupScores[$group] = min(1.0, max(0.0, $sum));
        }

        $score = 0.0;
        foreach ($weights as $group => $weight) {
            $score += (float) $weight * ($groupScores[$group] ?? 0.0);
        }
        $score = (int) round(min(100.0, max(0.0, $score * 100)));

        // --- data completeness ---
        $applicable = 0;
        $measured = 0;
        foreach ($signals as $signal) {
            if (! $signal->countsForCompleteness()) {
                continue;
            }
            $applicable++;
            if ($signal->wasMeasured()) {
                $measured++;
            }
        }
        $completeness = $applicable > 0 ? round($measured / $applicable, 3) : 0.0;

        // --- hard override ---
        $hardLevel = null;
        $hardSignal = null;
        foreach ($signals as $signal) {
            if (! $signal->hardOverride || $signal->hardOverrideLevel === null) {
                continue;
            }
            if ($hardLevel === null || $this->levelRank($signal->hardOverrideLevel) > $this->levelRank($hardLevel)) {
                $hardLevel = $signal->hardOverrideLevel;
                $hardSignal = $signal->key;
            }
        }

        // --- band level ---
        $bandLevel = match (true) {
            $score >= (int) ($levels['critical_at'] ?? 75) => RiskAssessment::LEVEL_CRITICAL,
            $score >= (int) ($levels['high_at'] ?? 50) => RiskAssessment::LEVEL_HIGH,
            $score >= (int) ($levels['medium_at'] ?? 25) => RiskAssessment::LEVEL_MEDIUM,
            default => RiskAssessment::LEVEL_LOWER,
        };

        // --- resolve final level ---
        $insufficientData = $screeningStatus !== RiskAssessment::STATUS_COMPLETED
            || $completeness < $minCompleteness;

        if ($hardLevel !== null) {
            // A measured hard flag always wins.
            $level = $this->levelRank($hardLevel) >= $this->levelRank($bandLevel) ? $hardLevel : $bandLevel;
        } elseif ($insufficientData) {
            $level = RiskAssessment::LEVEL_UNKNOWN;
        } else {
            $level = $bandLevel;
        }

        $mainEligible = in_array($level, RiskAssessment::MAIN_LIST_LEVELS, true)
            && ! $insufficientData
            && $hardSignal === null;

        $notes = $hardSignal !== null
            ? "hard override: {$hardSignal} -> {$hardLevel}"
            : ($insufficientData ? "risk unknown: data completeness {$completeness}" : "score band: {$score} -> {$bandLevel}");

        return new RiskAssessmentResult(
            level: $level,
            score: $score,
            dataCompleteness: $completeness,
            screeningStatus: $screeningStatus,
            hardOverrideSignal: $hardSignal,
            mainListEligible: $mainEligible,
            signals: $signals,
            groupScores: $groupScores,
            notes: $notes,
        );
    }

    private function levelRank(string $level): int
    {
        return match ($level) {
            RiskAssessment::LEVEL_CRITICAL => 4,
            RiskAssessment::LEVEL_HIGH => 3,
            RiskAssessment::LEVEL_MEDIUM => 2,
            RiskAssessment::LEVEL_LOWER => 1,
            default => 0,
        };
    }
}
