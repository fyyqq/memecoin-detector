<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 5-minute trending capture for a token in a timeframe (6h / 24h).
 *
 * History — never overwritten once its `capture_bucket` has passed. A token that
 * stops trending keeps all of its snapshots. `tracked_trend_score` is our
 * transparent deterministic INTERNAL ranking, NOT DexScreener's proprietary
 * `trendingScore`. `source` is always `dexscreener_meta`.
 */
class TrendingSnapshot extends Model
{
    public const TIMEFRAME_6H = '6h';

    public const TIMEFRAME_24H = '24h';

    public const TIMEFRAMES = [self::TIMEFRAME_6H, self::TIMEFRAME_24H];

    public const SOURCE = 'dexscreener_meta';

    protected $fillable = [
        'token_id',
        'chain_id',
        'token_address',
        'pair_address',
        'dex_id',
        'symbol',
        'name',
        'is_memecoin_candidate',
        'timeframe',
        'capture_bucket',
        'trend_rank',
        'tracked_trend_score',
        'trend_score_components',
        'trend_appearances',
        'market_cap',
        'liquidity_usd',
        'volume_usd',
        'price_change_pct',
        'transaction_count',
        'pair_created_at',
        'trending_meta_slug',
        'trending_meta_name',
        'source',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'capture_bucket' => 'integer',
            'trend_rank' => 'integer',
            'tracked_trend_score' => 'float',
            'trend_score_components' => 'array',
            'trend_appearances' => 'integer',
            'market_cap' => 'float',
            'liquidity_usd' => 'float',
            'volume_usd' => 'float',
            'price_change_pct' => 'float',
            'transaction_count' => 'integer',
            'pair_created_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
