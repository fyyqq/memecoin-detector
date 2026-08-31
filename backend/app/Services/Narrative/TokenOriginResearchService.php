<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects candidate sources for "Why was this coin created?".
 *
 * Runs each active {@see NarrativeResearchProvider} with an `origin` context —
 * official project links, well-established reference / meme-provenance sources,
 * early reputable articles, and our own stored ORIGIN / TOKEN_METADATA evidence.
 * A provider that throws or is unavailable is skipped; the run continues.
 */
class TokenOriginResearchService
{
    public function __construct(private readonly NarrativeResearchProviderRegistry $registry) {}

    public function research(Token $token, CarbonImmutable $now): NarrativeResearchOutcome
    {
        $context = NarrativeResearchContext::for($token, NarrativeResearchContext::SECTION_ORIGIN, $now);

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
                Log::warning('Narrative origin research provider threw', [
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
            section: NarrativeResearchContext::SECTION_ORIGIN,
            candidates: $candidates,
            providersUsed: array_values(array_unique($providersUsed)),
            providerFailures: array_values(array_unique($providerFailures)),
        );
    }
}
