<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One timestamped FACT present around a {@see PumpEvent}.
 *
 * Evidence is stored SEPARATELY from interpretation. A record never asserts
 * causality — an article "published 12 minutes before the observed peak" is a
 * fact; "the article caused the pump" is not something this table represents.
 *
 * `relevance_score` (0-100) answers "how relevant is this to investigating the
 * event", NOT "probability it caused the event".
 */
class Evidence extends Model
{
    public const CATEGORY_MARKET = 'MARKET';

    public const CATEGORY_TOKEN_METADATA = 'TOKEN_METADATA';

    public const CATEGORY_ORIGIN = 'ORIGIN';

    public const CATEGORY_NEWS = 'NEWS';

    public const CATEGORY_RELATED_TOKEN = 'RELATED_TOKEN';

    /** Reserved for future collectors — no rows are written with these yet. */
    public const CATEGORY_LISTING = 'LISTING';

    public const CATEGORY_COMMUNITY = 'COMMUNITY';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    /** Laravel would singularize "Evidence" to "evidence"; the table is "evidences". */
    protected $table = 'evidences';

    protected $fillable = [
        'pump_event_id',
        'token_id',
        'category',
        'source',
        'source_url',
        'title',
        'observed_at',
        'published_at',
        'relevance_score',
        'confidence',
        'summary',
        'raw_reference',
        'dedupe_hash',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'relevance_score' => 'integer',
        ];
    }

    /** @return BelongsTo<PumpEvent, $this> */
    public function pumpEvent(): BelongsTo
    {
        return $this->belongsTo(PumpEvent::class);
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
