<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\QualifiedCandidate;

/**
 * Output of one discovery run: the qualified candidates (age <= 30d AND observed
 * peak market cap >= threshold), a diagnostics funnel (counts only), and a
 * bounded sample of age-eligible tokens that did NOT qualify — with their size
 * figures, so an FDV-only / below-threshold outcome stays auditable.
 *
 * Never carries raw provider payloads.
 */
final readonly class DiscoveryResult
{
    /**
     * @param  list<QualifiedCandidate>  $candidates  Qualified + limited.
     * @param  array<string,int>  $diagnostics  Funnel counts.
     * @param  list<array<string,mixed>>  $notQualifiedSample  {token_key, chain_id, symbol, reason, current_market_cap, fdv, observed_peak_market_cap, age_days}.
     * @param  int|null  $ingestionRunId  The `ingestion_runs` row for this run.
     */
    public function __construct(
        public array $candidates,
        public array $diagnostics,
        public array $notQualifiedSample = [],
        public ?int $ingestionRunId = null,
    ) {}
}
