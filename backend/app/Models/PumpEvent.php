<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One detected significant upward move in a token's OBSERVATION SERIES.
 *
 * Timestamps are snapshot `observed_at` values — this is an "observed pump",
 * never a claim about exact tick-level market boundaries. `detection_score` is a
 * deterministic 0-100 STRENGTH score, not a probability or a prediction.
 *
 * `volume_h24_change_ratio` / `txns_h24_change_ratio` are ROLLING 24h ratios
 * (see config/pump.php) — directional confirmation only, not interval volume.
 */
class PumpEvent extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    /** low < medium < high — for merge comparisons. */
    public const CONFIDENCE_RANK = [
        self::CONFIDENCE_LOW => 0,
        self::CONFIDENCE_MEDIUM => 1,
        self::CONFIDENCE_HIGH => 2,
    ];

    protected $fillable = [
        'token_id',
        'started_at',
        'peak_at',
        'ended_at',
        'start_market_cap',
        'peak_market_cap',
        'start_price_usd',
        'peak_price_usd',
        'market_cap_change_pct',
        'price_change_pct',
        'volume_h24_change_ratio',
        'txns_h24_change_ratio',
        'duration_minutes',
        'detection_score',
        'confidence',
        'status',
        'evidence_collected_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'peak_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'evidence_collected_at' => 'immutable_datetime',
            'start_market_cap' => 'float',
            'peak_market_cap' => 'float',
            'start_price_usd' => 'float',
            'peak_price_usd' => 'float',
            'market_cap_change_pct' => 'float',
            'price_change_pct' => 'float',
            'volume_h24_change_ratio' => 'float',
            'txns_h24_change_ratio' => 'float',
            'duration_minutes' => 'integer',
            'detection_score' => 'integer',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /** @return HasMany<Evidence, $this> */
    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * The single AI explanation for this event (Step 16C). Upserted on
     * regeneration — one row per event.
     *
     * @return HasOne<PumpExplanation, $this>
     */
    public function explanation(): HasOne
    {
        return $this->hasOne(PumpExplanation::class);
    }
}
