<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The market state of a token as observed by our detector at `observed_at`.
 *
 * Many rows per token are expected — each discovery run appends one. Raw
 * provider payloads are never stored here.
 */
class MarketSnapshot extends Model
{
    protected $fillable = [
        'token_id',
        'observed_at',
        'price_usd',
        'market_cap',
        'fdv',
        'liquidity_usd',
        'volume_h24',
        'price_change_h24',
        'txns_h24',
        'buys_h24',
        'sells_h24',
        'primary_pair_address',
        'primary_dex_id',
        'earliest_pair_created_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'earliest_pair_created_at' => 'immutable_datetime',
            'price_usd' => 'float',
            'market_cap' => 'float',
            'fdv' => 'float',
            'liquidity_usd' => 'float',
            'volume_h24' => 'float',
            'price_change_h24' => 'float',
            'txns_h24' => 'integer',
            'buys_h24' => 'integer',
            'sells_h24' => 'integer',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
