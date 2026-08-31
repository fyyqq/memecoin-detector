<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Summary of one {@see PumpExplanationService::explain()} run. Counts only.
 */
final readonly class PumpExplanationRunResult
{
    public function __construct(
        public int $eventsAnalyzed,
        public int $explanationsGenerated,
        public int $skippedCooldown,
        public int $skippedNoEvidence,
        public int $failed,
        public float $durationSeconds,
    ) {}

    public function skipped(): int
    {
        return $this->skippedCooldown + $this->skippedNoEvidence;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'events_analyzed' => $this->eventsAnalyzed,
            'explanations_generated' => $this->explanationsGenerated,
            'skipped' => $this->skipped(),
            'skipped_cooldown' => $this->skippedCooldown,
            'skipped_no_evidence' => $this->skippedNoEvidence,
            'failed' => $this->failed,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
