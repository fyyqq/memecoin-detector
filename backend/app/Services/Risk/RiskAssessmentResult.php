<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskAssessment;

/**
 * The computed risk assessment for one token, before persistence.
 */
final class RiskAssessmentResult
{
    /**
     * @param  RiskAssessment::LEVEL_*  $level
     * @param  RiskAssessment::STATUS_*  $screeningStatus
     * @param  list<RiskSignalDraft>  $signals
     * @param  array<string,float>  $groupScores  0..1 per dimension
     */
    public function __construct(
        public readonly string $level,
        public readonly int $score,
        public readonly float $dataCompleteness,
        public readonly string $screeningStatus,
        public readonly ?string $hardOverrideSignal,
        public readonly bool $mainListEligible,
        public readonly array $signals,
        public readonly array $groupScores,
        public readonly string $notes = '',
    ) {}

    public function isUnknown(): bool
    {
        return $this->level === RiskAssessment::LEVEL_UNKNOWN;
    }

    /** BAD signals, strongest severity first — the "failed_signals" for RISK WATCH. */
    public function failingSignals(): array
    {
        $rank = [
            'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4,
        ];
        $failing = array_values(array_filter($this->signals, fn (RiskSignalDraft $s): bool => $s->state === 'BAD'));
        usort($failing, fn (RiskSignalDraft $a, RiskSignalDraft $b): int => ($rank[$a->severity] ?? 9) <=> ($rank[$b->severity] ?? 9));

        return $failing;
    }
}
