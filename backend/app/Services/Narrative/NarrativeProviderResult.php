<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * The raw structured object a provider extracted from the model response (the
 * `record_token_narrative` tool input) plus the model name. NOT yet validated.
 */
final readonly class NarrativeProviderResult
{
    /**
     * @param  array<string,mixed>  $structuredOutput
     */
    public function __construct(
        public array $structuredOutput,
        public string $modelName,
    ) {}
}
