<?php

declare(strict_types=1);

namespace App\Services\Ranking;

final readonly class MonthlyChampionRunResult
{
    /**
     * @param  list<array<string,mixed>>  $months  one entry per ranked row touched
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
            'finalized_buckets' => $this->finalized,
            'provisional_buckets' => $this->provisional,
            'no_verified_result_buckets' => $this->noVerifiedChampion,
            'skipped_settled' => $this->skippedSettled,
            'duration_seconds' => $this->durationSeconds,
            'rows_touched' => count($this->months),
        ];
    }
}
