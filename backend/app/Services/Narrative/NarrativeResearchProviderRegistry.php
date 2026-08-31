<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Services\Narrative\Providers\GdeltNarrativeResearchProvider;
use App\Services\Narrative\Providers\InternalEvidenceResearchProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the ORDERED, CONFIGURABLE list of active research-source providers
 * from `config('narrative.research_providers')`.
 *
 * `internal` is always kept (and moved first) — it is the offline baseline that
 * lets a report be produced even when every external provider is down.
 * Unknown provider keys are ignored.
 */
class NarrativeResearchProviderRegistry
{
    /** @var array<string,class-string<NarrativeResearchProvider>> */
    private const KNOWN = [
        'internal' => InternalEvidenceResearchProvider::class,
        'gdelt' => GdeltNarrativeResearchProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<NarrativeResearchProvider>
     */
    public function active(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('narrative.research_providers', ['internal']);

        $keys = array_values(array_unique(array_filter(
            array_map(static fn ($k): string => mb_strtolower(trim((string) $k)), $configured),
            static fn (string $k): bool => isset(self::KNOWN[$k]),
        )));

        // Guarantee the offline baseline, first.
        $keys = array_values(array_unique(['internal', ...$keys]));

        $providers = [];
        foreach ($keys as $key) {
            /** @var NarrativeResearchProvider $provider */
            $provider = $this->container->make(self::KNOWN[$key]);
            $providers[] = $provider;
        }

        return $providers;
    }
}
