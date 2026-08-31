<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Vendor-agnostic contract for turning a {@see PumpExplanationPrompt} into a
 * structured explanation object. The concrete vendor is chosen by
 * `config('ai.provider')` and bound in a service provider — nothing in
 * {@see PumpExplanationService} references a specific vendor.
 */
interface PumpExplanationProvider
{
    /**
     * @throws PumpExplanationProviderException on any failure — never returns a
     *                                          fabricated result.
     */
    public function generate(PumpExplanationPrompt $prompt): ProviderResult;

    /** Short stable identifier persisted with the explanation, e.g. "anthropic". */
    public function name(): string;
}
