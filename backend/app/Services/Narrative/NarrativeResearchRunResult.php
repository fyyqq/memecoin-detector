<?php

declare(strict_types=1);

namespace App\Services\Narrative;

final readonly class NarrativeResearchRunResult
{
    public function __construct(
        public int $tokensConsidered,
        public int $completed,
        public int $partial,
        public int $failed,
        public int $skippedCooldown,
        public int $sourcesRecorded,
        public int $providerFailures,
        public float $durationSeconds,
    ) {}

    /**
     * @return array<string,int|float>
     */
    public function toArray(): array
    {
        return [
            'tokens_considered' => $this->tokensConsidered,
            'completed' => $this->completed,
            'partial' => $this->partial,
            'failed' => $this->failed,
            'skipped_cooldown' => $this->skippedCooldown,
            'sources_recorded' => $this->sourcesRecorded,
            'provider_failures' => $this->providerFailures,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
