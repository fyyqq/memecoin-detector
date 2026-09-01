<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The current deterministic risk assessment for a token (Step 24) — one row per
 * token, upserted and re-evaluable.
 *
 * `risk_level` is LOWER / MEDIUM / HIGH / CRITICAL / UNKNOWN. UNKNOWN is a
 * distinct state — "insufficient security data", never "high risk" and never
 * "safe". `risk_score` is a deterministic 0-100 heuristic screening score
 * (higher = more risk) — it is NOT a probability of scam / rug / loss.
 *
 * This model never affects market-cap qualification, `observed_peak_market_cap`,
 * pump events or evidence — it only routes an already-qualified token between
 * the MAIN LIST and RISK WATCH.
 */
class RiskAssessment extends Model
{
    public const LEVEL_LOWER = 'LOWER';

    public const LEVEL_MEDIUM = 'MEDIUM';

    public const LEVEL_HIGH = 'HIGH';

    public const LEVEL_CRITICAL = 'CRITICAL';

    public const LEVEL_UNKNOWN = 'UNKNOWN';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /** Levels allowed on the MAIN LIST (subject to the maturity + completeness rules). */
    public const MAIN_LIST_LEVELS = [self::LEVEL_LOWER, self::LEVEL_MEDIUM];

    protected $fillable = [
        'token_id',
        'risk_level',
        'risk_score',
        'data_completeness',
        'screening_status',
        'hard_override_signal',
        'main_list_eligible',
        'screened_at',
        'provider_version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'integer',
            'data_completeness' => 'float',
            'main_list_eligible' => 'boolean',
            'screened_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /** @return HasMany<RiskSignal, $this> */
    public function signals(): HasMany
    {
        return $this->hasMany(RiskSignal::class);
    }
}
