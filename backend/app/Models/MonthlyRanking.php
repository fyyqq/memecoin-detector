<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bucket's "Monthly Top Memecoin" for a calendar month (Step 22, corrected)
 * — at most ONE row per `(year, month, chain_bucket)`, buckets being
 * `solana | robinhood | bsc | base | other`.
 *
 * The champion is the single memecoin with the strongest SUPPORTED performance
 * in the eligible universe for that month + bucket, scored primarily on observed
 * market-cap growth (baseline -> peak within the month). NOT the biggest token,
 * highest market cap, most liquidity, or first to cross $5M. Risk score and AI
 * are NEVER used to select the winner. The score is not a prediction of returns.
 */
class MonthlyRanking extends Model
{
    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_BEST_SUPPORTED_CANDIDATE = 'best_supported_candidate';

    public const STATUS_NO_VERIFIED_CHAMPION = 'no_verified_champion';

    public const STATUS_FUTURE = 'future';

    /** Statuses that carry a real champion token. */
    public const STATUSES_WITH_TOKEN = [
        self::STATUS_PROVISIONAL,
        self::STATUS_FINALIZED,
        self::STATUS_BEST_SUPPORTED_CANDIDATE,
    ];

    /** Our own MarketSnapshots established the winner. */
    public const SOURCE_INTERNAL_OBSERVED = 'internal_observed';

    /**
     * A source DIRECTLY establishes the DexScreener historical rank for the
     * month/bucket. Only used when the evidence actually says "DexScreener #1".
     */
    public const SOURCE_EXACT_DEXSCREENER_RANK = 'exact_dexscreener_rank';

    /**
     * The best-supported candidate from historical research — a real token that
     * clearly leads the bucket on available performance evidence, but NOT a
     * claimed exact DexScreener rank.
     */
    public const SOURCE_BEST_SUPPORTED_HISTORICAL = 'best_supported_historical_performer';

    // Retained for back-compat with earlier rows / config.
    public const SOURCE_DEXSCREENER = 'dexscreener';

    public const SOURCE_WEB_RESEARCH = 'web_research';

    public const SOURCE_OTHER_VERIFIED = 'other_verified_source';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    protected $fillable = [
        'year',
        'month',
        'chain_bucket',
        'token_id',
        // Denormalized identity for a historically-researched champion that is
        // NOT in our `tokens` table.
        'champion_name',
        'champion_symbol',
        'champion_chain_id',
        'champion_token_address',
        'champion_image_url',
        'status',
        'performance_score',
        'baseline_market_cap',
        'peak_market_cap',
        'market_cap_growth_pct',
        'peak_expansion_ratio',
        'activity_score',
        'observation_count',
        'observation_coverage_ratio',
        'scoring_breakdown',
        'source_type',
        'source_reference',
        'source_evidence',
        'age_uncertain',
        'confidence',
        'finalized_at',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'performance_score' => 'float',
            'baseline_market_cap' => 'float',
            'peak_market_cap' => 'float',
            'market_cap_growth_pct' => 'float',
            'peak_expansion_ratio' => 'float',
            'activity_score' => 'float',
            'observation_count' => 'integer',
            'observation_coverage_ratio' => 'float',
            'scoring_breakdown' => 'array',
            'source_evidence' => 'array',
            'age_uncertain' => 'boolean',
            'finalized_at' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * A row is immutable during normal scheduler operation once it has been
     * finalized OR recorded as a best-supported candidate (both are "the past
     * month has been decided"). Only `--force` recomputes it.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_FINALIZED,
            self::STATUS_BEST_SUPPORTED_CANDIDATE,
            self::STATUS_NO_VERIFIED_CHAMPION,
        ], true) && $this->finalized_at !== null;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    /**
     * The champion's identity for the API, whether it is a tracked `Token`
     * (`token_id`) or a denormalized historically-researched winner
     * (`champion_*`). Null when there is no champion.
     *
     * @return array{id:?int,symbol:?string,name:?string,chain_id:?string,chain_bucket:string,token_address:?string,image_url:?string}|null
     */
    public function championIdentity(): ?array
    {
        if (! in_array($this->status, self::STATUSES_WITH_TOKEN, true)) {
            return null;
        }

        if ($this->token_id !== null && $this->relationLoaded('token') && $this->token !== null) {
            $token = $this->token;

            return [
                'id' => (int) $token->id,
                'symbol' => $token->symbol,
                'name' => $token->name,
                'chain_id' => $token->chain_id,
                'chain_bucket' => $this->chain_bucket,
                'token_address' => $token->token_address,
                'image_url' => $token->image_url,
            ];
        }

        if ($this->champion_symbol !== null || $this->champion_name !== null) {
            return [
                'id' => null,
                'symbol' => $this->champion_symbol,
                'name' => $this->champion_name,
                'chain_id' => $this->champion_chain_id,
                'chain_bucket' => $this->chain_bucket,
                'token_address' => $this->champion_token_address,
                'image_url' => $this->champion_image_url,
            ];
        }

        return null;
    }

    /** First instant of this ranking's calendar month (UTC). */
    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1, 0, 0, 0, 'UTC');
    }
}
