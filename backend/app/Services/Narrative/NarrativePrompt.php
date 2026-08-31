<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * Everything a {@see NarrativeExplanationProvider} needs for one token, split
 * into trusted instructions (`systemPrompt`) and untrusted data (`dataBlock`).
 *
 * `dataBlock` holds the token identity, the ranked origin sources, the ranked
 * popularity sources, and our internal market-timing facts. Every source carries
 * an `id` — the ONLY ids the model may cite in `source_ids`.
 */
final readonly class NarrativePrompt
{
    /**
     * @param  array{token: array<string,mixed>, origin_sources: list<array<string,mixed>>, popularity_sources: list<array<string,mixed>>}  $dataBlock
     * @param  list<int>  $suppliedSourceIds
     */
    public function __construct(
        public string $systemPrompt,
        public array $dataBlock,
        public array $suppliedSourceIds,
    ) {}

    public function dataBlockJson(): string
    {
        return json_encode(
            $this->dataBlock,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}
