<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\ProviderResult;
use App\Services\AI\PumpExplanationPrompt;
use App\Services\AI\PumpExplanationProvider;
use App\Services\AI\PumpExplanationProviderException;

/**
 * The safe default when no real AI vendor is configured (or AI_PROVIDER is
 * unknown). It never touches the network and never fabricates an explanation —
 * it always fails, so the run is recorded as `failed` with a clear message
 * rather than producing a made-up interpretation.
 */
class NullPumpExplanationProvider implements PumpExplanationProvider
{
    public function generate(PumpExplanationPrompt $prompt): ProviderResult
    {
        throw new PumpExplanationProviderException(
            'No AI provider configured (AI_PROVIDER). Set AI_PROVIDER=anthropic and ANTHROPIC_API_KEY to generate explanations.',
        );
    }

    public function name(): string
    {
        return 'null';
    }
}
