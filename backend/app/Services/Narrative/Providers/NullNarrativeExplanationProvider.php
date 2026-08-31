<?php

declare(strict_types=1);

namespace App\Services\Narrative\Providers;

use App\Services\Narrative\NarrativeExplanationProvider;
use App\Services\Narrative\NarrativeExplanationProviderException;
use App\Services\Narrative\NarrativePrompt;
use App\Services\Narrative\NarrativeProviderResult;

/**
 * The safe default when no real AI vendor is configured (or the provider name is
 * unknown). It never touches the network and never fabricates a synthesis — it
 * always fails, so the report is recorded `failed` (with its collected sources
 * still persisted) rather than inventing an origin / popularity story.
 */
class NullNarrativeExplanationProvider implements NarrativeExplanationProvider
{
    public function generate(NarrativePrompt $prompt): NarrativeProviderResult
    {
        throw new NarrativeExplanationProviderException(
            'No narrative AI provider configured. Set NARRATIVE_AI_PROVIDER=anthropic and ANTHROPIC_API_KEY to synthesise narratives.',
        );
    }

    public function name(): string
    {
        return 'null';
    }
}
