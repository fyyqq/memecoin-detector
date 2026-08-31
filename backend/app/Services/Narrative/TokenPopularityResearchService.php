<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects candidate sources for "Why did this coin become popular?".
 *
 * Runs each active {@see NarrativeResearchProvider} with a `popularity` context —
 * launch / first-attention / catalyst / listing / community coverage, plus our
 * own internal market timing (PumpEvents, $5M crossings, pool age, NEWS /
 * RELATED_TOKEN evidence). Market timing is neutral fact, never proof of cause.
 * A provider that throws or is unavailable is skipped; the run continues.
 */
class TokenPopularityResearchService
{
    public function __construct(private readonly NarrativeResearchProviderRegistry $registry) {}

    public function research(Token $token, CarbonImmutable $now): NarrativeResearchOutcome
    {
        $context = NarrativeResearchContext::for($token, NarrativeResearchContext::SECTION_POPULARITY, $now);

        /** @var list<NarrativeSourceCandidate> $candidates */
        $candidates = [];
        $providersUsed = [];
        $providerFailures = [];

        foreach ($this->registry->active() as $provider) {
            if (! $provider->isAvailable()) {
                continue;
            }
            try {
                $found = $provider->research($context);
            } catch (Throwable $e) {
                $providerFailures[] = $provider->name();
                Log::warning('Narrative popularity research provider threw', [
                    'provider' => $provider->name(),
                    'token_id' => $token->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($provider->lastCallFailed()) {
                $providerFailures[] = $provider->name();
            }
            if ($found !== []) {
                $providersUsed[] = $provider->name();
                $candidates = [...$candidates, ...$found];
            }
        }

        return new NarrativeResearchOutcome(
            section: NarrativeResearchContext::SECTION_POPULARITY,
            candidates: $candidates,
            providersUsed: array_values(array_unique($providersUsed)),
            providerFailures: array_values(array_unique($providerFailures)),
        );
    }
}
