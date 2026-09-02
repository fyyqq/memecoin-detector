<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\MonthlyRanking;
use Carbon\CarbonImmutable;

/**
 * One candidate for a month + chain bucket Top 3 (Step 25), returned by a
 * {@see MonthlyChampionResearchProvider}.
 *
 * Providers NEVER fabricate: every candidate must be identity-resolvable
 * (name + symbol + chain, ideally a contract address) and carry the sources
 * that support it. The service re-validates eligibility ($5M–$1B MARKET CAP —
 * never FDV, the right bucket, the right month, ≤ 30-day trading age) and ranks
 * survivors with the deterministic participation formula
 * ({@see MonthlyPerformanceCalculator::scoreHistorical}).
 *
 * `holderCount` / `volumeUsd` are the participation inputs. A `null` value is
 * honestly UNKNOWN — it is dropped from the score, never treated as 0, never a
 * current count standing in for a past month.
 */
final class MonthlyResearchCandidate
{
    /**
     * @param  list<MonthlyResearchSource>  $sources
     * @param  MonthlyRanking::SOURCE_*  $sourceType
     */
    public function __construct(
        public readonly string $name,
        public readonly string $symbol,
        public readonly string $chainId,
        public readonly ?string $tokenAddress,
        public readonly ?string $imageUrl,
        public readonly ?float $baselineMarketCap,
        /** Month-peak OBSERVED / VERIFIED market cap (never FDV). */
        public readonly ?float $peakMarketCap,
        /** Representative monthly volume in USD, if known. */
        public readonly ?float $volumeUsd,
        /** Monthly-representative holder count, if known — never a current count. */
        public readonly ?int $holderCount,
        /** Best defensible earliest trading / pool date — NOT "token creation date". */
        public readonly ?CarbonImmutable $launchDate,
        public readonly bool $ageUncertain,
        public readonly string $sourceType,
        public readonly string $suggestedConfidence,
        public readonly array $sources,
        public readonly string $explanation,
        /** Set only by the internal-observed provider. */
        public readonly ?int $observationCount = null,
        public readonly ?float $observationCoverageRatio = null,
        public readonly ?int $tokenId = null,
        /** Filled by the scorer. */
        public ?float $performanceScore = null,
        public ?float $holderStrength = null,
        public ?float $volumeStrength = null,
        public ?float $marketCapStrength = null,
        public ?float $marketCapGrowthPct = null,
        public ?float $peakExpansionRatio = null,
        public ?float $activityScore = null,
        /** Filled by the service after eligibility checks. */
        public ?string $rejectReason = null,
    ) {}

    public function isInternalObserved(): bool
    {
        return $this->sourceType === MonthlyRanking::SOURCE_INTERNAL_OBSERVED;
    }

    public function hasResolvedIdentity(): bool
    {
        return trim($this->name) !== '' && trim($this->symbol) !== '' && trim($this->chainId) !== '';
    }

    public function hasStrongSource(): bool
    {
        foreach ($this->sources as $source) {
            if ($source->isStrong()) {
                return true;
            }
        }

        return false;
    }

    public function sourceCount(): int
    {
        return count($this->sources);
    }

    /** A stable per-token key for a deterministic tie-break. */
    public function tokenKey(): string
    {
        return mb_strtolower(trim($this->chainId)).':'.mb_strtolower(trim((string) $this->tokenAddress)).':'.mb_strtolower(trim($this->symbol));
    }

    /**
     * Peak market cap is present AND inside the qualification band `[$min, $max)`
     * — floor inclusive, ceiling EXCLUSIVE (exactly $max does not qualify).
     */
    public function peakInBand(float $min, float $max): bool
    {
        return $this->peakMarketCap !== null
            && $this->peakMarketCap >= $min
            && $this->peakMarketCap < $max;
    }

    public function canComputeGrowth(): bool
    {
        return $this->baselineMarketCap !== null
            && $this->baselineMarketCap > 0.0
            && $this->peakMarketCap !== null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function sourcesAsArray(): array
    {
        return array_map(fn (MonthlyResearchSource $s): array => $s->toArray(), $this->sources);
    }
}
