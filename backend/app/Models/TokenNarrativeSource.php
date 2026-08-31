<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One concise source behind a {@see TokenNarrativeReport} section.
 *
 * Source metadata + a one-sentence claim only — never scraped HTML page bodies.
 * `published_at` is a real date or NULL (never fabricated). The narrative JSON
 * references these rows by `id`.
 */
class TokenNarrativeSource extends Model
{
    public const SECTION_ORIGIN = 'origin';

    public const SECTION_POPULARITY = 'popularity';

    /** Coarse source category — drives the quality tier. */
    public const TYPE_OFFICIAL = 'official';

    public const TYPE_NEWS = 'news';

    public const TYPE_SOCIAL = 'social';

    public const TYPE_MARKET = 'market';

    public const TYPE_COMMUNITY = 'community';

    public const TYPE_REFERENCE = 'reference';

    public const SOURCE_TYPES = [
        self::TYPE_OFFICIAL,
        self::TYPE_NEWS,
        self::TYPE_SOCIAL,
        self::TYPE_MARKET,
        self::TYPE_COMMUNITY,
        self::TYPE_REFERENCE,
    ];

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    protected $fillable = [
        'token_narrative_report_id',
        'token_id',
        'section',
        'source_type',
        'source_name',
        'source_url',
        'title',
        'published_at',
        'accessed_at',
        'claim',
        'relevance_score',
        'confidence',
        'provider',
        'dedupe_hash',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'accessed_at' => 'immutable_datetime',
            'relevance_score' => 'integer',
        ];
    }

    /** @return BelongsTo<TokenNarrativeReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TokenNarrativeReport::class, 'token_narrative_report_id');
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }
}
