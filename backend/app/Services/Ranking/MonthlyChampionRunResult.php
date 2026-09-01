<?php

declare(strict_types=1);

namespace App\Services\Ranking;

final readonly class MonthlyChampionRunResult
{
    /**
     * @param  list<array<string,mixed>>  $months  one entry per (month, bucket) touched
     */
    public function __construct(
        public array $months,
        public int $finalized,
        public int $provisional,
        public int $bestSupportedCandidate,
        public int $noVerifiedChampion,
        public int $skippedSettled,
        public float $durationSeconds,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'finalized' => $this->finalized,
            'provisional' => $this->provisional,
            'best_supported_candidate' => $this->bestSupportedCandidate,
            'no_verified_champion' => $this->noVerifiedChampion,
            'skipped_settled' => $this->skippedSettled,
            'duration_seconds' => $this->durationSeconds,
            'buckets_touched' => count($this->months),
        ];
    }
}
