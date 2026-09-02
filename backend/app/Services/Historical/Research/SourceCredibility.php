<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * The trust tier of a historical evidence source, highest → lowest.
 *
 * The string values match the credibility tiers already used by
 * `App\Services\Ranking\MonthlyResearchSource` so the two systems stay
 * consistent. `rank()` (0 = strongest) drives {@see HistoricalConfidence}.
 */
enum SourceCredibility: string
{
    case PrimaryMarketData = 'primary_market_data';
    case HistoricalProvider = 'historical_provider';
    case ArchivedDexscreener = 'archived_dexscreener';
    case ReputableReporting = 'reputable_reporting';
    case Secondary = 'secondary';
    case LowQuality = 'low_quality';

    /** 0 = strongest. */
    public function rank(): int
    {
        return match ($this) {
            self::PrimaryMarketData => 0,
            self::HistoricalProvider => 1,
            self::ArchivedDexscreener => 2,
            self::ReputableReporting => 3,
            self::Secondary => 4,
            self::LowQuality => 5,
        };
    }

    /** Primary market data / historical provider / archived DexScreener. */
    public function isStrong(): bool
    {
        return $this->rank() <= 2;
    }

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Secondary;
    }
}
