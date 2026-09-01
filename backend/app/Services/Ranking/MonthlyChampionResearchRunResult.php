<?php

declare(strict_types=1);

namespace App\Services\Ranking;

final readonly class MonthlyChampionResearchRunResult
{
    /**
     * @param  list<array<string,mixed>>  $buckets  one entry per bucket touched
     * @param  list<string>  $providersUsed
     */
    public function __construct(
        public array $buckets,
        public int $finalized,
        public int $bestSupportedCandidate,
        public int $noVerifiedChampion,
        public int $future,
        public int $skipped,
        public int $providerFailures,
        public array $providersUsed,
        public float $durationSeconds,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'finalized' => $this->finalized,
            'best_supported_candidate' => $this->bestSupportedCandidate,
            'no_verified_champion' => $this->noVerifiedChampion,
            'future' => $this->future,
            'skipped' => $this->skipped,
            'provider_failures' => $this->providerFailures,
            'providers_used' => $this->providersUsed,
            'duration_seconds' => $this->durationSeconds,
            'buckets_touched' => count($this->buckets),
        ];
    }
}
