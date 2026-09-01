<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\Token;

/**
 * One token's monthly performance evaluation. `status`:
 *
 *   eligible                  — a valid championship candidate
 *   insufficient_observation  — real candidate but observed too sparsely to win
 *   ineligible                — failed an eligibility gate (age / $5M / $200M /
 *                               no baseline / no eligible snapshot)
 */
final readonly class MonthlyCandidate
{
    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_INSUFFICIENT_OBSERVATION = 'insufficient_observation';

    public const STATUS_INELIGIBLE = 'ineligible';

    /**
     * @param  array<string,mixed>  $breakdown
     */
    public function __construct(
        public Token $token,
        public string $status,
        public ?string $ineligibleReason,
        public ?float $baselineMarketCap,
        public ?float $peakMarketCap,
        public ?float $marketCapGrowthPct,
        public ?float $peakExpansionRatio,
        public ?float $activityScore,      // 0..100
        public int $observationCount,
        public ?float $observationCoverageRatio,
        public ?float $performanceScore,   // 0..100
        public array $breakdown = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->status === self::STATUS_ELIGIBLE;
    }
}
