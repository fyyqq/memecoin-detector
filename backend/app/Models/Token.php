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
 * since `first_observed_at` — it is NOT a guaranteed lifetime / all-time high.
 */
class Token extends Model
{
    protected $fillable = [
        'chain_id',
        'token_address',
        'symbol',
        'name',
        'earliest_pair_created_at',
        'first_observed_at',
        'last_observed_at',
        'observed_peak_market_cap',
        'observed_peak_market_cap_at',
    ];

    protected function casts(): array
    {
        return [
            'earliest_pair_created_at' => 'immutable_datetime',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'observed_peak_market_cap' => 'float',
            'observed_peak_market_cap_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<MarketSnapshot, $this> */
    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
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
}
