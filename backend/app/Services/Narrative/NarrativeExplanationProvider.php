<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * Vendor-agnostic contract for turning a {@see NarrativePrompt} (token + ranked
 * sources + internal market timing) into a structured origin + popularity
 * synthesis.
 *
 * Chosen by `config('narrative.ai.provider')` and bound in AppServiceProvider —
 * NOT coupled to Anthropic and NOT reusing the PumpExplanation binding. API
 * credentials stay server-side.
 */
interface NarrativeExplanationProvider
{
    /**
     * @throws NarrativeExplanationProviderException on any failure — never a
     *                                               fabricated result.
     */
    public function generate(NarrativePrompt $prompt): NarrativeProviderResult;

    /** Short stable identifier persisted with the report, e.g. "anthropic". */
    public function name(): string;
}
