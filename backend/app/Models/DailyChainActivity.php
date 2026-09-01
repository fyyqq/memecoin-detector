<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One materialised "Chain Market Activity" row per chain bucket per day.
 *
 * Upserted by `collect-trending` from `tokens` + each token's latest
 * `market_snapshot` (deduplicated token-level representative-pair volume, behind
 * the market-integrity gate). `total_volume_usd` is REPORTED volume — never
 * claimed organic. `GET /api/memecoins/chain-activity` reads this table only.
 */
class DailyChainActivity extends Model
{
    protected $table = 'daily_chain_activity';

    protected $fillable = [
        'date',
        'chain_bucket',
        'total_volume_usd',
        'total_liquidity_usd',
        'active_token_count',
        'top_token_id',
        'top_token_address',
        'top_token_symbol',
        'top_token_volume',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'total_volume_usd' => 'float',
            'total_liquidity_usd' => 'float',
            'active_token_count' => 'integer',
            'top_token_volume' => 'float',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function topToken(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'top_token_id');
    }
}
