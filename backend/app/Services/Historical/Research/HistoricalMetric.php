<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * The historical metrics a {@see HistoricalResearchProvider} can be asked for
 * when reconstructing a past "Monthly Top Memecoins" bucket (Step 26).
 *
 * The string value is what `monthly_ranking_evidence.metric` stores. Terminology
 * matches the rest of the codebase — `holders` (GeckoTerminal `holders.count` /
 * risk `holder_count`), `volume` (`volume_h24` / `total_volumes`), `market_cap`
 * (OBSERVED / VERIFIED circulating market cap — never FDV), `ohlcv` (price/volume
 * candles), `identity` (`chain_id` + `token_address` + name/symbol), `pool_date`
 * (earliest DEX pool creation — NOT "token creation date").
 */
enum HistoricalMetric: string
{
    case Holders = 'holders';
    case Volume = 'volume';
    case MarketCap = 'market_cap';
    case Ohlcv = 'ohlcv';
    case Identity = 'identity';
    case PoolDate = 'pool_date';

    /**
     * A metric whose evidence carries a single scalar `value_numeric`
     * (holders / volume / market cap). `identity` / `ohlcv` / `pool_date` are
     * structured — their detail lives in `metadata` / `observed_at`.
     */
    public function isScalar(): bool
    {
        return match ($this) {
            self::Holders, self::Volume, self::MarketCap => true,
            self::Identity, self::Ohlcv, self::PoolDate => false,
        };
    }

    /** A metric that feeds the participation score (holders / volume / market cap). */
    public function isScoringInput(): bool
    {
        return $this->isScalar();
    }

    public function label(): string
    {
        return match ($this) {
            self::Holders => 'Holder count',
            self::Volume => 'Trading volume',
            self::MarketCap => 'Market cap',
            self::Ohlcv => 'OHLCV series',
            self::Identity => 'Token identity',
            self::PoolDate => 'Earliest pool date',
        };
    }
}
