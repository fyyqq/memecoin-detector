<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * Wraps the {@see NarrativeExplanationProvider} + {@see NarrativeExplanationValidator}.
 *
 * One provider call returns both sections; each is validated independently, so
 * an invalid `popularity` section never discards a valid `origin` one. A total
 * provider failure throws {@see NarrativeExplanationProviderException} for the
 * caller to handle (keep an existing good report, else mark `failed`).
 */
class NarrativeExplanationService
{
    public function __construct(
        private readonly NarrativeExplanationProvider $provider,
        private readonly NarrativeExplanationValidator $validator,
    ) {}

    public function synthesize(NarrativePrompt $prompt): NarrativeSynthesisResult
    {
        $result = $this->provider->generate($prompt);
        $output = $result->structuredOutput;

        $originData = null;
        $originError = null;
        try {
            $originData = $this->validator->validateOrigin($output['origin'] ?? null, $prompt->suppliedSourceIds);
        } catch (InvalidNarrativeException $e) {
            $originError = $e->getMessage();
        }

        $popularityData = null;
        $popularityError = null;
        try {
            $popularityData = $this->validator->validatePopularity($output['popularity'] ?? null, $prompt->suppliedSourceIds);
        } catch (InvalidNarrativeException $e) {
            $popularityError = $e->getMessage();
        }

        return new NarrativeSynthesisResult(
            providerName: $this->provider->name(),
            modelName: $result->modelName,
            originData: $originData,
            originError: $originError,
            popularityData: $popularityData,
            popularityError: $popularityError,
        );
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }
}
