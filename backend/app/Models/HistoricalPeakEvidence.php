<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current historical-peak determination for a token — one row per token,
 * upserted and re-evaluable.
 *
 * `status` is the tiered evidence label. `peak_value_usd` is the figure the
 * label is based on; for HISTORICAL_ESTIMATE it is an **FDV basis** value
 * (`evidence_basis = fdv_total_supply`), never a verified circulating market
 * cap. A status of UNKNOWN is **not** a claim that the token never reached the
 * threshold.
 *
 * Only CURRENT_OBSERVATION and HISTORICAL_VERIFIED qualify a token for the main
 * ≥ $5M market-cap universe. HISTORICAL_ESTIMATE is an informational secondary
 * signal only — an estimated historical FDV, NOT a verified/observed market cap.
 */
class HistoricalPeakEvidence extends Model
{
    public const STATUS_CURRENT_OBSERVATION = 'CURRENT_OBSERVATION';

    public const STATUS_HISTORICAL_VERIFIED = 'HISTORICAL_VERIFIED';

    public const STATUS_HISTORICAL_ESTIMATE = 'HISTORICAL_ESTIMATE';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const SOURCE_DEXSCREENER = 'dexscreener';

    public const SOURCE_COINGECKO = 'coingecko';

    public const SOURCE_GECKOTERMINAL = 'geckoterminal';

    public const BASIS_MARKET_CAP = 'market_cap';

    public const BASIS_FDV_TOTAL_SUPPLY = 'fdv_total_supply';

    public const BASIS_CURRENT_MARKET_CAP = 'current_market_cap';

    /**
     * Statuses that QUALIFY a token for the main ≥ $5M market-cap universe
     * (when `peak_value_usd` clears the threshold) — verified or observed
     * market cap only. HISTORICAL_ESTIMATE is deliberately excluded.
     */
    public const QUALIFYING_STATUSES = [
        self::STATUS_CURRENT_OBSERVATION,
        self::STATUS_HISTORICAL_VERIFIED,
    ];

    /**
     * Informational-only statuses — an estimated historical FDV, never a
     * verified/observed market cap, and never sufficient for main qualification.
     */
    public const INFORMATIONAL_STATUSES = [
        self::STATUS_HISTORICAL_ESTIMATE,
    ];

    // "evidence" does not pluralize; pin the table name to match the migration.
    protected $table = 'historical_peak_evidences';

    protected $fillable = [
        'token_id',
        'status',
        'peak_value_usd',
        'peak_observed_at',
        'first_verified_crossing_at',
        'evidence_source',
        'evidence_basis',
        'source_reference',
        'historical_window_start',
        'historical_window_end',
        'confidence',
        'checked_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'peak_value_usd' => 'float',
            'peak_observed_at' => 'immutable_datetime',
            'first_verified_crossing_at' => 'immutable_datetime',
            'historical_window_start' => 'immutable_datetime',
            'historical_window_end' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * Does this evidence qualify the token for the main bounded market-cap
     * universe? True only for a VERIFIED / OBSERVED market cap
     * (CURRENT_OBSERVATION or HISTORICAL_VERIFIED) whose peak sits in
     * `[$min, $max]`. An FDV-basis estimate never qualifies.
     *
     * `$max` defaults to null (no ceiling) so existing single-argument callers
     * keep the old "peak >= floor" behaviour.
     */
    public function qualifies(float $min, ?float $max = null): bool
    {
        if (! in_array($this->status, self::QUALIFYING_STATUSES, true) || $this->peak_value_usd === null) {
            return false;
        }

        if ($this->peak_value_usd < $min) {
            return false;
        }

        return $max === null || $this->peak_value_usd <= $max;
    }

    /**
     * A verified/observed market cap that cleared the floor but whose peak
     * exceeds the ceiling — i.e. it qualified once but is now outside the
     * requested $5M–$1B universe.
     */
    public function peakAboveCeiling(float $min, float $max): bool
    {
        return in_array($this->status, self::QUALIFYING_STATUSES, true)
            && $this->peak_value_usd !== null
            && $this->peak_value_usd >= $min
            && $this->peak_value_usd > $max;
    }

    /**
     * An FDV-basis historical estimate — informational only, and NOT sufficient
     * to qualify the token for the main list.
     */
    public function isInformationalEstimate(): bool
    {
        return $this->status === self::STATUS_HISTORICAL_ESTIMATE
            && $this->peak_value_usd !== null;
    }
}
