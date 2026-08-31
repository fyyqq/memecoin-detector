<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "$5M crossing" — the earliest observation at which a VERIFIED / OBSERVED
 * market cap cleared the threshold for this token.
 *
 *   CURRENT_OBSERVATION  our own MarketSnapshot saw market_cap >= $5M
 *   HISTORICAL_VERIFIED  CoinGecko verified a historical market cap >= $5M
 *
 * HISTORICAL_ESTIMATE (FDV basis) is NEVER a crossing — an estimated FDV is not
 * a verified market cap.
 *
 * One row per (token, type). A token may hold both types; the representative
 * crossing is the strongest: HISTORICAL_VERIFIED > CURRENT_OBSERVATION.
 *
 * Written only by the ingestion / qualification pipeline
 * (App\Services\Historical\QualificationEventRecorder) — never by a read API.
 */
class QualificationEvent extends Model
{
    public const TYPE_CURRENT_OBSERVATION = 'CURRENT_OBSERVATION';

    public const TYPE_HISTORICAL_VERIFIED = 'HISTORICAL_VERIFIED';

    /** Strongest first — used to pick the representative crossing. */
    public const TYPE_PRECEDENCE = [
        self::TYPE_HISTORICAL_VERIFIED,
        self::TYPE_CURRENT_OBSERVATION,
    ];

    protected $fillable = [
        'token_id',
        'type',
        'crossed_at',
        'threshold_usd',
        'evidence_status',
        'source',
        'market_cap_value',
    ];

    protected function casts(): array
    {
        return [
            'crossed_at' => 'immutable_datetime',
            'threshold_usd' => 'integer',
            'market_cap_value' => 'float',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * Rank for the representative-crossing sort (lower = stronger).
     */
    public function precedenceRank(): int
    {
        $rank = array_search($this->type, self::TYPE_PRECEDENCE, true);

        return $rank === false ? PHP_INT_MAX : $rank;
    }
}
