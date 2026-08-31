<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\TokenNarrativeSource;
use Carbon\CarbonImmutable;

/**
 * One candidate source a {@see NarrativeResearchProvider} found. Metadata + a
 * concise claim only — never a scraped page body. `publishedAt` is a real date
 * or null; it is never fabricated.
 */
final readonly class NarrativeSourceCandidate
{
    /**
     * @param  'origin'|'popularity'  $section
     * @param  value-of<TokenNarrativeSource::SOURCE_TYPES>  $sourceType
     * @param  'low'|'medium'|'high'  $confidence
     */
    public function __construct(
        public string $section,
        public string $sourceType,
        public string $sourceName,
        public ?string $sourceUrl,
        public ?string $title,
        public ?CarbonImmutable $publishedAt,
        public string $claim,
        public int $relevanceScore,
        public string $confidence,
        public string $provider,
    ) {}

    public function dedupeHash(): string
    {
        return sha1(implode('|', [
            $this->section,
            $this->sourceType,
            mb_strtolower(trim($this->sourceUrl ?? $this->sourceName)),
            mb_strtolower(trim($this->title ?? '')),
            $this->publishedAt?->toDateString() ?? '',
            mb_substr(mb_strtolower(trim($this->claim)), 0, 120),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    public function toAttributes(CarbonImmutable $accessedAt): array
    {
        return [
            'section' => $this->section,
            'source_type' => $this->sourceType,
            'source_name' => mb_substr($this->sourceName, 0, 120),
            'source_url' => $this->sourceUrl !== null ? mb_substr($this->sourceUrl, 0, 1024) : null,
            'title' => $this->title !== null ? mb_substr($this->title, 0, 500) : null,
            'published_at' => $this->publishedAt,
            'accessed_at' => $accessedAt,
            'claim' => mb_substr(trim($this->claim), 0, 1000),
            'relevance_score' => max(0, min(100, $this->relevanceScore)),
            'confidence' => in_array($this->confidence, ['low', 'medium', 'high'], true) ? $this->confidence : 'low',
            'provider' => mb_substr($this->provider, 0, 32),
            'dedupe_hash' => $this->dedupeHash(),
        ];
    }
}
