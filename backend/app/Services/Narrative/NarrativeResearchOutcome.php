<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * The raw research yield for one section, before ranking / persistence.
 */
final readonly class NarrativeResearchOutcome
{
    /**
     * @param  'origin'|'popularity'  $section
     * @param  list<NarrativeSourceCandidate>  $candidates
     * @param  list<string>  $providersUsed
     * @param  list<string>  $providerFailures
     */
    public function __construct(
        public string $section,
        public array $candidates,
        public array $providersUsed,
        public array $providerFailures,
    ) {}
}
