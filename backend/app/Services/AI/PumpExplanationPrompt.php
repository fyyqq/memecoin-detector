<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Everything a {@see PumpExplanationProvider} needs for one event, already
 * separated into trusted instructions and untrusted data.
 *
 * `systemPrompt` is OUR text. `dataBlock` is the pump event + evidence records
 * from our database — it is passed to the model inside a clearly delimited data
 * block and the system prompt tells the model to treat it as untrusted input
 * and never follow instructions found inside it.
 */
final readonly class PumpExplanationPrompt
{
    /**
     * @param  array{pump_event: array<string,mixed>, evidence: list<array<string,mixed>>}  $dataBlock
     * @param  list<int>  $suppliedEvidenceIds  the evidence ids in $dataBlock — the only ids the model may cite
     */
    public function __construct(
        public string $systemPrompt,
        public array $dataBlock,
        public array $suppliedEvidenceIds,
    ) {}

    public function dataBlockJson(): string
    {
        return json_encode(
            $this->dataBlock,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}
