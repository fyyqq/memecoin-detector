<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One token-level narrative report (Step 21) — an AI synthesis, grounded in
 * {@see TokenNarrativeSource} rows + our own stored {@see Evidence} / market
 * history, of two separate questions:
 *
 *   origin      — why was this coin created?
 *   popularity  — why did this coin become popular?
 *
 * The AI is an interpreter only. Every factual claim inside the `_explanation_json`
 * columns cites `token_narrative_sources.id` values. A section may be `completed`
 * while the other is `partial` / `failed`; the overall report is then `partial`.
 */
class TokenNarrativeReport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const SECTION_ORIGIN = 'origin';

    public const SECTION_POPULARITY = 'popularity';

    public const SECTIONS = [self::SECTION_ORIGIN, self::SECTION_POPULARITY];

    /** origin_type — the model may not invent a value. */
    public const ORIGIN_TYPES = [
        'COMMUNITY_MEME',
        'INTERNET_MEME',
        'CELEBRITY_MEME',
        'POLITICAL_MEME',
        'CULTURAL_REFERENCE',
        'VIRAL_EVENT',
        'ANIMAL_MEME',
        'NARRATIVE_TOKEN',
        'UTILITY_PLUS_MEME',
        'UNKNOWN',
    ];

    /** popularity timeline event types — the model may not invent a value. */
    public const POPULARITY_EVENT_TYPES = [
        'MEME_ORIGIN',
        'LAUNCH',
        'MEDIA_ATTENTION',
        'SOCIAL_ATTENTION',
        'CELEBRITY_ATTENTION',
        'EXCHANGE_LISTING',
        'NARRATIVE_EVENT',
        'RELATED_TOKEN',
        'COMMUNITY_EVENT',
        'MARKET_ACTIVITY',
        'OTHER',
    ];

    public const CONFIDENCE = ['low', 'medium', 'high'];

    protected $fillable = [
        'token_id',
        'origin_status',
        'origin_summary',
        'origin_explanation_json',
        'popularity_status',
        'popularity_summary',
        'popularity_explanation_json',
        'overall_confidence',
        'overall_status',
        'research_started_at',
        'research_completed_at',
        'model_provider',
        'model_name',
        'research_providers_used',
        'generated_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'origin_explanation_json' => 'array',
            'popularity_explanation_json' => 'array',
            'research_providers_used' => 'array',
            'research_started_at' => 'immutable_datetime',
            'research_completed_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /** @return HasMany<TokenNarrativeSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(TokenNarrativeSource::class);
    }
}
