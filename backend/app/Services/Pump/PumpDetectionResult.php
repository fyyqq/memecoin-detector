<?php

declare(strict_types=1);

namespace App\Services\Pump;

/**
 * Summary of one {@see PumpDetectionService::detect()} run. Counts only.
 */
final readonly class PumpDetectionResult
{
    public function __construct(
        public int $tokensAnalyzed,
        public int $eventsCreated,
        public int $eventsUpdated,
        public int $eventsCompletedBySweep,
        public int $activeEvents,
        public int $completedEvents,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'tokens_analyzed' => $this->tokensAnalyzed,
            'events_created' => $this->eventsCreated,
            'events_updated' => $this->eventsUpdated,
            'events_completed_by_sweep' => $this->eventsCompletedBySweep,
            'active_events' => $this->activeEvents,
            'completed_events' => $this->completedEvents,
        ];
    }
}
