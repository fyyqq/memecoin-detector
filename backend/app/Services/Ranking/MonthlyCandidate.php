<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\Token;

/**
 * One token's monthly performance evaluation (Step 25, Top 3). `status`:
 *
 *   eligible                  — a valid Top-3 candidate
 *   insufficient_observation  — real candidate but observed too sparsely
 *   ineligible                — failed an eligibility gate (age / $5M / $200M /
 *                               no eligible snapshot / zero volume)
 *
 * The selection score is `performanceScore` = 100·Σ(w·strength)/Σ(w) over the
 * KNOWN components (holder_strength · 0.40 + volume_strength · 0.35 +
 * market_cap_strength · 0.25; an UNKNOWN component drops out and the weights
 * renormalize). `marketCapGrowthPct` / `peakExpansionRatio` / `activityScore`
 * are INFO-ONLY context, never part of the score or the ordering.
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
        // --- score inputs -------------------------------------------------
        public ?int $holderCount,          // monthly max / representative; null = UNKNOWN
        public ?float $monthlyVolumeUsd,   // month's representative volume
        public ?float $monthMarketCap,     // month's peak observed/verified MC
        public ?float $holderStrength,     // [0,1] or null when holder_count UNKNOWN
        public ?float $volumeStrength,     // [0,1]
        public ?float $marketCapStrength,  // [0,1]
        public ?float $performanceScore,   // 0..100
        // --- info-only context -----------------------------------------
        public ?float $baselineMarketCap,
        public ?float $peakMarketCap,
        public ?float $marketCapGrowthPct,
        public ?float $peakExpansionRatio,
        public ?float $activityScore,      // 0..100
        public int $observationCount,
        public ?float $observationCoverageRatio,
        public array $breakdown = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->status === self::STATUS_ELIGIBLE;
    }

    /** A stable per-token key for the deterministic final tie-break. */
    public function tokenKey(): string
    {
        return mb_strtolower(trim((string) $this->token->chain_id)).':'.mb_strtolower(trim((string) $this->token->token_address));
    }
}
