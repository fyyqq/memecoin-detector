<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Carbon\CarbonImmutable;

/**
 * One evidence fact a collector produced, before persistence.
 *
 * A collector NEVER asserts causality. `relevanceScore` (0-100) = "how relevant
 * to investigating this event", not "probability it caused the event".
 */
final readonly class EvidenceCandidate
{
    public function __construct(
        public string $category,
        public string $source,
        public ?string $sourceUrl,
        public ?string $title,
        public ?CarbonImmutable $observedAt,
        public ?CarbonImmutable $publishedAt,
        public int $relevanceScore,
        public string $confidence,
        public string $summary,
        public ?string $rawReference,
    ) {}

    /** sha1(category|source|source_url|title|published_at) — idempotency key. */
    public function dedupeHash(): string
    {
        return sha1(implode('|', [
            $this->category,
            $this->source,
            $this->sourceUrl ?? '',
            mb_strtolower(trim((string) $this->title)),
            $this->publishedAt?->toIso8601String() ?? '',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'category' => $this->category,
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'title' => $this->title,
            'observed_at' => $this->observedAt,
            'published_at' => $this->publishedAt,
            'relevance_score' => max(0, min(100, $this->relevanceScore)),
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'raw_reference' => $this->rawReference,
            'dedupe_hash' => $this->dedupeHash(),
        ];
    }
}
