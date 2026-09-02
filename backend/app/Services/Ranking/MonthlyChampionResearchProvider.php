<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * Vendor-agnostic contract for RESEARCHING a past month's + chain bucket's top
 * memecoin (Step 22, corrected; historical backfill Step 25).
 *
 * Implementations must:
 *  - never throw into the pipeline — an outage returns [] and is logged;
 *  - never fabricate a candidate, an archived ranking, a URL, or a date;
 *  - never scrape search-engine result pages;
 *  - resolve entity identity by NAME + SYMBOL + CHAIN (+ contract address where
 *    reliably identifiable) — never by symbol alone;
 *  - report MARKET CAP, never FDV, for the $5M–$1B check (ceiling exclusive);
 *  - never claim "DexScreener #1" unless a source actually establishes it
 *    (use `MonthlyRanking::SOURCE_EXACT_DEXSCREENER_RANK` only then).
 *
 * Providers are ONLY invoked by `memecoins:research-monthly-champions` — never
 * by the read API, never by the daily finalize pass.
 */
interface MonthlyChampionResearchProvider
{
    /** Short stable id, e.g. "internal_observed", "seed_file". */
    public function name(): string;

    /** False => skipped this run (disabled / no credentials / no data). */
    public function isAvailable(): bool;

    /**
     * @return list<MonthlyResearchCandidate>
     */
    public function research(MonthlyResearchContext $context): array;

    /** True when the most recent research() call hit a provider error. */
    public function lastCallFailed(): bool;
}
