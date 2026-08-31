<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-generated, evidence-grounded interpretation of one {@see PumpEvent}
 * (Step 16C).
 *
 * The model that produced `explanation_json` was an INTERPRETER of the Evidence
 * records our database already had — it never contributed facts of its own,
 * never browsed, and never asserted causality from temporal correlation. Every
 * factual statement inside `explanation_json` cites evidence IDs.
 *
 * Regeneratable: `generated_at` is tracked and the row is upserted as evidence
 * accrues. An explanation is never permanently frozen.
 */
class PumpExplanation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** The ONLY primary-catalyst values the model may return. */
    public const CATALYSTS = [
        'OFFICIAL_ANNOUNCEMENT',
        'CELEBRITY_INFLUENCER',
        'NARRATIVE_ROTATION',
        'EXCHANGE_LISTING',
        'COMMUNITY_TAKEOVER',
        'AIRDROP_BUYBACK',
        'WHALE_ACTIVITY',
        'RELATED_TOKEN_SPILLOVER',
        'LIQUIDITY_EVENT',
        'MARKET_ACTIVITY',
        'UNKNOWN',
    ];

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    protected $fillable = [
        'pump_event_id',
        'status',
        'summary',
        'primary_catalyst',
        'confidence',
        'explanation_json',
        'evidence_count',
        'model_provider',
        'model_name',
        'error_message',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'explanation_json' => 'array',
            'evidence_count' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PumpEvent, $this> */
    public function pumpEvent(): BelongsTo
    {
        return $this->belongsTo(PumpEvent::class);
    }
}
