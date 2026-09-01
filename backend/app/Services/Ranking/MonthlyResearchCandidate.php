<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\MonthlyRanking;
use Carbon\CarbonImmutable;

/**
 * One candidate #1-performing memecoin for a month + chain bucket (Step 25),
 * returned by a {@see MonthlyChampionResearchProvider}.
 *
 * Providers NEVER fabricate: every candidate must be identity-resolvable
 * (name + symbol + chain, ideally a contract address) and carry the sources
 * that support it. The service re-validates eligibility ($5M–$200M MARKET CAP —
 * never FDV, the right bucket, the right month, ≤ 30-day trading age) and ranks
 * survivors with the deterministic performance formula.
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
        public readonly ?float $peakMarketCap,
        /** A market-activity proxy (median/representative 24h volume in USD), if known. */
        public readonly ?float $volumeUsd,
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

    /** Peak market cap is present AND inside the $5M–$200M band. */
    public function peakInBand(float $min, float $max): bool
    {
        return $this->peakMarketCap !== null
            && $this->peakMarketCap >= $min
            && $this->peakMarketCap <= $max;
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
