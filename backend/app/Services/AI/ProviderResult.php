<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The raw structured object a provider extracted from the model response, plus
 * the model name that produced it. NOT yet validated — {@see PumpExplanationValidator}
 * still has to accept it.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string,mixed>  $structuredOutput
     */
    public function __construct(
        public array $structuredOutput,
        public string $modelName,
    ) {}
}
