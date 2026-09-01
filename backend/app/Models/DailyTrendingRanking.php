<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily trending archive — one row per token per day per chain bucket per
 * timeframe. Read-only from the API's point of view: `collect-trending` writes
 * it, `GET /api/memecoins/trending/history` reads it and NEVER recomputes.
 *
 * `chain_bucket` is a display bucket (solana/robinhood/bsc/base/other); the
 * token's real chain stays in `chain_id`.
 */
class DailyTrendingRanking extends Model
{
    protected $fillable = [
        'date',
        'chain_bucket',
        'timeframe',
        'token_id',
        'chain_id',
        'token_address',
        'symbol',
        'name',
        'best_rank',
        'best_score',
        'peak_market_cap',
        'peak_volume',
        'peak_liquidity',
        'appearances',
        'trending_meta_slug',
        'trending_meta_name',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'best_rank' => 'integer',
            'best_score' => 'float',
            'peak_market_cap' => 'float',
            'peak_volume' => 'float',
            'peak_liquidity' => 'float',
            'appearances' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
