<?php

declare(strict_types=1);

namespace App\Services\Evidence;

/**
 * Summary of one {@see EvidenceCollectionService::collect()} run. Counts only —
 * no interpretation.
 */
final readonly class EvidenceCollectionResult
{
    /**
     * @param  array<string, int>  $recordsByCategory  category => records written/refreshed this run
     */
    public function __construct(
        public int $eventsAnalyzed,
        public int $eventsSkippedByCooldown,
        public int $eventsWithNewEvidence,
        public array $recordsByCategory,
        public int $totalEvidenceRecords,
        public int $newEvidenceRecords,
        public int $providerFailures,
        public float $durationSeconds,
    ) {}

    public function categoryCount(string $category): int
    {
        return $this->recordsByCategory[$category] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'events_analyzed' => $this->eventsAnalyzed,
            'events_skipped_by_cooldown' => $this->eventsSkippedByCooldown,
            'events_with_new_evidence' => $this->eventsWithNewEvidence,
            'records_by_category' => $this->recordsByCategory,
            'total_evidence_records' => $this->totalEvidenceRecords,
            'new_evidence_records' => $this->newEvidenceRecords,
            'provider_failures' => $this->providerFailures,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
