<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A memecoin, identified by (chain_id, token_address).
 *
 * `observed_peak_market_cap` is the highest market cap captured by OUR snapshots
 * since `first_observed_at` — it is NOT a guaranteed lifetime / all-time high,
 * and it is NEVER overwritten with an external or estimated value.
 *
 * `historical_peak_value` (+`_at`, +`_status`) is a SEPARATE denormalized
 * headline of the historical qualification engine (see {@see HistoricalPeakEvidence}).
 * `historical_peak_value` holds a **verified/observed market cap only** —
 * CURRENT_OBSERVATION or CoinGecko HISTORICAL_VERIFIED — and is what qualifies a
 * token for the main ≥ $5M list alongside `observed_peak_market_cap`.
 *
 * `historical_estimate_fdv_usd` (+`_at`) holds a GeckoTerminal FDV-basis
 * ESTIMATE (peak price × total supply) — informational only, kept strictly
 * separate, and NEVER sufficient for main qualification. None of these columns
 * ever overwrite `observed_peak_market_cap`.
 */
class Token extends Model
{
    protected $fillable = [
        'chain_id',
        'token_address',
        'symbol',
        'name',
        'website_url',
        'twitter_url',
        'telegram_url',
        'image_url',
        'metadata_updated_at',
        'earliest_pair_created_at',
        'first_observed_at',
        'last_observed_at',
        'observed_peak_market_cap',
        'observed_peak_market_cap_at',
        'historical_peak_value',
        'historical_peak_value_at',
        'historical_peak_status',
        'historical_estimate_fdv_usd',
        'historical_estimate_fdv_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_updated_at' => 'immutable_datetime',
            'earliest_pair_created_at' => 'immutable_datetime',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'observed_peak_market_cap' => 'float',
            'observed_peak_market_cap_at' => 'immutable_datetime',
            'historical_peak_value' => 'float',
            'historical_peak_value_at' => 'immutable_datetime',
            'historical_estimate_fdv_usd' => 'float',
            'historical_estimate_fdv_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<MarketSnapshot, $this> */
    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }

    /** @return HasMany<PumpEvent, $this> */
    public function pumpEvents(): HasMany
    {
        return $this->hasMany(PumpEvent::class);
    }

    /**
     * The most recent observation for this token. Uses a window-function
     * subquery so it can be eager-loaded without N+1.
     *
     * @return HasOne<MarketSnapshot, $this>
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(MarketSnapshot::class)->latestOfMany('observed_at');
    }

    /**
     * The token's current historical-peak evidence (one row per token).
     *
     * @return HasOne<HistoricalPeakEvidence, $this>
     */
    public function historicalPeakEvidence(): HasOne
    {
        return $this->hasOne(HistoricalPeakEvidence::class);
    }
}
