<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * Vendor-agnostic contract for FINDING research material about one token.
 *
 * Implementations must:
 *  - never throw into the pipeline — a provider outage returns [] and is logged;
 *  - never fabricate a source, URL, or date;
 *  - verify a source actually refers to THIS token (chain + address / name), not
 *    just a colliding ticker.
 *
 * The set of active providers is configurable (`config('narrative.research_providers')`).
 * A report is produced from whatever providers are available — one being
 * unavailable never fails the report.
 */
interface NarrativeResearchProvider
{
    /** Short stable id persisted on each source row + the report, e.g. "internal". */
    public function name(): string;

    /** False => skipped this run (disabled / no credentials / known unreachable). */
    public function isAvailable(): bool;

    /**
     * @return list<NarrativeSourceCandidate> candidates for `$context->section`
     */
    public function research(NarrativeResearchContext $context): array;

    /** True when the most recent research() call hit a provider error. */
    public function lastCallFailed(): bool;
}
