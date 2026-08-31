<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\QualifiedCandidate;
use App\DTOs\DexScreener\TokenCandidateData;
use App\Models\HistoricalPeakEvidence;
use App\Models\IngestionRun;
use App\Models\Token;
use App\Services\Historical\HistoricalQualificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sprint 1 discovery pipeline:
 *
 *   DISCOVER → ENRICH → NORMALIZE → AGE FILTER → CURRENT OBSERVATION CHECK
 *   → HISTORICAL LOOKUP → QUALIFICATION → PERSIST EVIDENCE → RETURN
 *
 * A token qualifies when age <= 30 days AND a VERIFIED / OBSERVED market cap has
 * EVER reached the threshold — proven by CURRENT_OBSERVATION (our own snapshot)
 * or HISTORICAL_VERIFIED (CoinGecko). HISTORICAL_ESTIMATE (GeckoTerminal FDV
 * basis) and UNKNOWN do NOT qualify — their evidence is still stored for
 * re-evaluation and shown as a secondary signal on the detail page.
 *
 * See docs/sprint-1-discovery.md and docs/historical-peak-reconnaissance.md.
 */
class DexScreenerDiscoveryService
{
    public function __construct(
        private readonly DexScreenerClient $client,
        private readonly DexScreenerNormalizer $normalizer,
        private readonly TokenObservationService $observations,
        private readonly HistoricalQualificationService $historical,
        private readonly SearchTermEngine $searchTerms,
    ) {}

    /**
     * Run the pipeline and wrap it in an {@see IngestionRun} record for
     * observability. Any unexpected failure marks the run `failed`, stores a
     * concise message, and is re-thrown for the caller to handle.
     *
     * @param  string|null  $chain  Optional chain_id filter (applied before enrichment).
     * @param  int|null  $limit  Max qualified candidates returned (clamped by the controller).
     * @param  string  $trigger  IngestionRun::TRIGGER_MANUAL | IngestionRun::TRIGGER_SCHEDULED
     */
    public function discover(
        ?string $chain = null,
        ?int $limit = null,
        string $trigger = IngestionRun::TRIGGER_MANUAL,
    ): DiscoveryResult {
        $run = IngestionRun::create([
            'started_at' => CarbonImmutable::now(),
            'status' => IngestionRun::STATUS_RUNNING,
            'trigger' => $trigger,
        ]);

        try {
            [$final, $diagnostics, $notQualifiedSample] = $this->runPipeline($chain, $limit);
        } catch (Throwable $e) {
            $run->update([
                'status' => IngestionRun::STATUS_FAILED,
                'completed_at' => CarbonImmutable::now(),
                'error_message' => Str::limit($e->getMessage(), 480),
            ]);

            Log::error('Memecoin discovery run failed', [
                'ingestion_run_id' => $run->id,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $run->update([
            'status' => IngestionRun::STATUS_COMPLETED,
            'completed_at' => CarbonImmutable::now(),
            'raw_candidates' => $diagnostics['raw_discovery_candidates'],
            'unique_candidates' => $diagnostics['unique_candidates'],
            'enriched_candidates' => $diagnostics['enriched_ok'],
            'age_eligible' => $diagnostics['age_eligible'],
            'snapshots_written' => $diagnostics['snapshots_written'],
            'new_tokens' => $diagnostics['new_tokens'],
            'peak_updated' => $diagnostics['peak_updated'],
            'qualified' => $diagnostics['qualified'],
            // Step 14 coverage metrics.
            'selected_for_enrichment' => $diagnostics['selected_for_enrichment'],
            'candidate_cap_dropped' => $diagnostics['candidate_cap_dropped'],
            'search_terms_used' => $diagnostics['search_terms_used'],
            'search_terms_with_results' => $diagnostics['search_terms_with_results'],
            'chains_discovered' => $diagnostics['chains_discovered'],
        ]);

        Log::info('Memecoin discovery run complete', ['ingestion_run_id' => $run->id] + $diagnostics);

        return new DiscoveryResult($final, $diagnostics, $notQualifiedSample, $run->id);
    }

    /**
     * The discovery pipeline itself. Returns
     * `[list<QualifiedCandidate>, array diagnostics, list<array> notQualifiedSample]`.
     * Diagnostics values are mostly ints; a few (`chains_discovered`,
     * `discovery_source_counts`, `search_term_categories`) are `array<string,int>`.
     *
     * @return array{0:list<QualifiedCandidate>,1:array<string,mixed>,2:list<array<string,mixed>>}
     */
    private function runPipeline(?string $chain, ?int $limit): array
    {
        $now = CarbonImmutable::now();
        $chain = $chain !== null ? mb_strtolower(trim($chain)) : null;

        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $maxEnrich = (int) config('dexscreener.limits.max_candidates_to_enrich');
        $candidateCap = (int) config('dexscreener.limits.discovery_candidate_cap');
        $limit ??= (int) config('dexscreener.limits.default_result_limit');

        // 1. DISCOVER — build the search-term plan, then collect + dedupe hits.
        $termPlan = $this->searchTerms->build();
        $discovery = $this->collectCandidates($chain, $termPlan['terms']);
        $rawCount = $discovery['raw_count'];
        $candidates = $discovery['candidates'];
        $termResults = $discovery['term_results'];

        $diagnostics = [
            'raw_discovery_candidates' => $rawCount,
            'unique_candidates' => count($candidates),
            'candidates_after_chain_filter' => count($candidates),
            'discovery_source_counts' => $discovery['source_counts'],
            'chains_discovered' => $discovery['chains'],
            'search_terms_used' => count($termResults),
            'search_terms_with_results' => count(array_filter($termResults, static fn (int $n): bool => $n > 0)),
            'search_terms_empty' => count(array_filter($termResults, static fn (int $n): bool => $n === 0)),
            'search_term_categories' => $termPlan['categories'],
            'search_term_budget' => $termPlan['budget'],
            'discovery_candidate_cap' => $candidateCap,
            'candidate_cap_dropped' => 0,
            'candidates_considered' => 0,
            'selected_for_enrichment' => 0,
            'enrichment_deferred' => 0,
            'enrichment_attempted' => 0,
            'enriched_ok' => 0,
            'enrichment_failed' => 0,
            'age_unknown' => 0,
            'older_than_max_age' => 0,
            'age_eligible' => 0,
            'market_cap_unknown' => 0,
            'snapshots_written' => 0,
            'persist_failed' => 0,
            'new_tokens' => 0,
            'existing_tokens' => 0,
            'peak_updated' => 0,
            'qualified' => 0,
            'qualified_from_current_observation' => 0,
            'not_qualified' => 0,
            'observed_peak_below_threshold' => 0,
            // Age-eligible, has an FDV-basis estimate >= $5M, but no verified /
            // observed market cap >= $5M — excluded from the main list (Step 17-fix).
            'not_qualified_fdv_estimate_only' => 0,
            // historical qualification (filled by HistoricalQualificationService)
            'historical_current_observation' => 0,
            'historical_verified' => 0,
            'historical_estimate' => 0,
            'historical_unknown' => 0,
            'historical_lookups_performed' => 0,
            'historical_lookups_skipped_cooldown' => 0,
            'historical_lookups_skipped_budget' => 0,
            'returned' => 0,
        ];

        // 1b. PRIORITIZE (deterministic) → CANDIDATE CAP → ENRICHMENT CAP.
        // Market cap is NOT used here — it is unknown until enrichment.
        $ordered = $this->prioritizeCandidates($candidates);

        $diagnostics['candidate_cap_dropped'] = max(0, count($ordered) - $candidateCap);
        $considered = array_slice($ordered, 0, max(1, $candidateCap));
        $diagnostics['candidates_considered'] = count($considered);

        // Every enriched, age-eligible candidate produces a stored observation,
        // so enrich up to the hard ceiling regardless of the result `limit`.
        $toEnrich = array_slice($considered, 0, max(0, $maxEnrich));
        $diagnostics['selected_for_enrichment'] = count($toEnrich);
        $diagnostics['enrichment_deferred'] = count($considered) - count($toEnrich);
        $diagnostics['enrichment_attempted'] = count($toEnrich);

        // 2. ENRICH (bounded concurrent batch) + 3. NORMALIZE -------------
        $pairsByToken = $this->client->tokenPairsBatch(array_map(
            fn (array $c): array => ['chain_id' => $c['chain_id'], 'token_address' => $c['token_address']],
            $toEnrich,
        ));

        /** @var list<TokenCandidateData> $normalized */
        $normalized = [];

        foreach ($toEnrich as $candidate) {
            $key = mb_strtolower($candidate['chain_id']).':'.mb_strtolower($candidate['token_address']);
            $pairs = $pairsByToken[$key] ?? [];

            $dto = $this->normalizer->normalize(
                $candidate['chain_id'],
                $candidate['token_address'],
                $pairs,
                $candidate['sources'],
                $now,
            );

            if ($dto === null) {
                $diagnostics['enrichment_failed']++;

                continue;
            }

            $diagnostics['enriched_ok']++;
            $normalized[] = $dto;
        }

        // 4. AGE FILTER → PERSIST TOKEN + SNAPSHOT + OBSERVED PEAK ---------
        /** @var list<array{token:Token,dto:TokenCandidateData,observation:RecordedObservation}> $ageEligible */
        $ageEligible = [];

        foreach ($normalized as $dto) {
            // Age is the only pre-persistence gate. `pairCreatedAt` is DEX pool
            // creation time, not token deployment — never guessed when null.
            if ($dto->earliestPairCreatedAt === null || $dto->ageDays === null) {
                $diagnostics['age_unknown']++;

                continue;
            }

            if ($dto->ageDays > $maxAgeDays) {
                $diagnostics['older_than_max_age']++;

                continue;
            }

            $diagnostics['age_eligible']++;

            try {
                $observation = $this->observations->record($dto, $now);
            } catch (Throwable $e) {
                Log::warning('Failed to persist token observation', [
                    'token' => $dto->tokenKey,
                    'error' => $e->getMessage(),
                ]);
                $diagnostics['persist_failed']++;

                continue;
            }

            $diagnostics['snapshots_written']++;
            $observation->tokenWasCreated ? $diagnostics['new_tokens']++ : $diagnostics['existing_tokens']++;

            if ($observation->peakUpdated) {
                $diagnostics['peak_updated']++;
            }

            if ($dto->marketCap === null) {
                $diagnostics['market_cap_unknown']++;
            }

            $ageEligible[] = ['token' => $observation->token, 'dto' => $dto, 'observation' => $observation];
        }

        // 5. CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP → PERSIST EVIDENCE
        // One evidence row per age-eligible token (upserted, re-evaluable).
        // observed_peak_market_cap is never touched here.
        ['evidence' => $evidenceByToken, 'stats' => $histStats] = $this->historical->qualify(
            array_map(
                static fn (array $e): array => [
                    'token' => $e['token'],
                    'chain_id' => $e['dto']->chainId,
                    'token_address' => $e['dto']->tokenAddress,
                ],
                $ageEligible,
            ),
            $now,
        );

        foreach ($histStats as $key => $value) {
            $diagnostics[$key] = $value;
        }

        // 6. QUALIFICATION -----------------------------------------------
        /** @var list<QualifiedCandidate> $qualified */
        $qualified = [];
        /** @var list<array<string,mixed>> $notQualifiedSample */
        $notQualifiedSample = [];
        $sampleCap = 50;

        foreach ($ageEligible as $entry) {
            $token = $entry['token'];
            $dto = $entry['dto'];
            $observation = $entry['observation'];
            $evidence = $evidenceByToken[$token->id] ?? null;
            $status = $evidence?->status ?? HistoricalPeakEvidence::STATUS_UNKNOWN;

            if ($evidence !== null && $evidence->qualifies($peakMin)) {
                $diagnostics['qualified']++;

                if ($status === HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION
                    && $observation->peakUpdated
                    && ($observation->previousObservedPeak === null || $observation->previousObservedPeak < $peakMin)) {
                    $diagnostics['qualified_from_current_observation']++;
                }

                $qualified[] = new QualifiedCandidate(
                    current: $dto,
                    observedPeakMarketCap: $token->observed_peak_market_cap,
                    observedPeakMarketCapAt: $token->observed_peak_market_cap_at,
                    observedSince: $token->first_observed_at ?? $now,
                    qualificationStatus: $status,
                    qualificationPeakValue: $evidence->peak_value_usd,
                    qualificationPeakAt: $evidence->peak_observed_at,
                    qualificationSource: $evidence->evidence_source,
                    qualificationBasis: $evidence->evidence_basis,
                );

                continue;
            }

            $diagnostics['not_qualified']++;
            $peak = $token->observed_peak_market_cap;

            $estimateOnly = $status === HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE;

            if ($estimateOnly) {
                $diagnostics['not_qualified_fdv_estimate_only']++;
            } elseif ($peak !== null) {
                $diagnostics['observed_peak_below_threshold']++;
            }

            if (count($notQualifiedSample) < $sampleCap) {
                $notQualifiedSample[] = [
                    'token_key' => $dto->tokenKey,
                    'chain_id' => $dto->chainId,
                    'symbol' => $dto->symbol,
                    // Never claims the token has never crossed the threshold.
                    'reason' => match (true) {
                        // FDV estimate >= $5M but no verified/observed market cap.
                        $estimateOnly => 'historical_fdv_estimate_only',
                        $peak === null => 'insufficient_historical_observation',
                        default => 'observed_peak_below_threshold',
                    },
                    'historical_peak_status' => $status,
                    'current_market_cap' => $dto->marketCap,
                    'fdv' => $dto->fdv,
                    'observed_peak_market_cap' => $peak,
                    'age_days' => $dto->ageDays,
                ];
            }
        }

        // Highest qualifying peak first (observed OR historical), then youngest.
        usort($qualified, function (QualifiedCandidate $a, QualifiedCandidate $b): int {
            $peakA = max($a->observedPeakMarketCap ?? 0.0, $a->qualificationPeakValue ?? 0.0);
            $peakB = max($b->observedPeakMarketCap ?? 0.0, $b->qualificationPeakValue ?? 0.0);

            return [$peakB, -($a->current->ageDays ?? PHP_INT_MAX)]
                <=> [$peakA, -($b->current->ageDays ?? PHP_INT_MAX)];
        });

        $final = array_slice($qualified, 0, max(0, $limit));
        $diagnostics['returned'] = count($final);

        return [$final, $diagnostics, $notQualifiedSample];
    }

    /**
     * Collect + de-duplicate raw discovery hits across all sources, tracking the
     * per-candidate signals used for pre-enrichment prioritization plus coverage
     * diagnostics (chains seen, per-term result counts, per-source counts).
     *
     * @param  list<string>  $terms  search terms from {@see SearchTermEngine}
     * @return array{
     *     raw_count: int,
     *     candidates: array<string,array{chain_id:string,token_address:string,token_key:string,sources:list<string>,boost:bool,profile_rank:int,search_hits:int,order:int}>,
     *     term_results: array<string,int>,
     *     chains: array<string,int>,
     *     source_counts: array{profile:int,boost:int,search:int}
     * }
     */
    private function collectCandidates(?string $chain, array $terms): array
    {
        /** @var array<string,array{chain_id:string,token_address:string,token_key:string,sources:list<string>,boost:bool,profile_rank:int,search_hits:int,order:int}> $candidates */
        $candidates = [];
        $rawCount = 0;
        $order = 0;

        $add = function (?string $chainId, ?string $tokenAddress, string $source, ?int $profileRank = null) use (&$candidates, &$rawCount, &$order, $chain): void {
            $chainId = is_string($chainId) ? mb_strtolower(trim($chainId)) : '';
            $tokenAddress = is_string($tokenAddress) ? trim($tokenAddress) : '';

            if ($chainId === '' || $tokenAddress === '') {
                return;
            }

            $rawCount++;

            if ($chain !== null && $chainId !== $chain) {
                return;
            }

            $key = $chainId.':'.mb_strtolower($tokenAddress);

            if (! isset($candidates[$key])) {
                $candidates[$key] = [
                    'chain_id' => $chainId,
                    'token_address' => $tokenAddress,
                    'token_key' => $key,
                    'sources' => [],
                    'boost' => false,
                    'profile_rank' => PHP_INT_MAX,
                    'search_hits' => 0,
                    'order' => $order++,
                ];
            }

            if (! in_array($source, $candidates[$key]['sources'], true)) {
                $candidates[$key]['sources'][] = $source;
            }

            match ($source) {
                'boost' => $candidates[$key]['boost'] = true,
                'search' => $candidates[$key]['search_hits']++,
                'profile' => $candidates[$key]['profile_rank'] = min(
                    $candidates[$key]['profile_rank'],
                    $profileRank ?? PHP_INT_MAX,
                ),
                default => null,
            };
        };

        // A. latest token profiles — list position is a freshness signal.
        foreach (array_values($this->client->latestTokenProfiles()) as $i => $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'profile', $i);
        }

        // B. latest token boosts + C. top token boosts
        foreach ($this->client->latestTokenBoosts() as $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
        }
        foreach ($this->client->topTokenBoosts() as $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
        }

        // D. curated + trending-meta + ecosystem search terms.
        /** @var array<string,int> $termResults */
        $termResults = [];
        foreach ($terms as $term) {
            $pairs = $this->client->search($term);
            $termResults[$term] = count($pairs);

            foreach ($pairs as $pair) {
                $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
                $add($pair['chainId'] ?? null, $base['address'] ?? null, 'search');
            }
        }

        // Coverage aggregates over the UNIQUE candidate set.
        $chains = [];
        $sourceCounts = ['profile' => 0, 'boost' => 0, 'search' => 0];

        foreach ($candidates as $candidate) {
            $chains[$candidate['chain_id']] = ($chains[$candidate['chain_id']] ?? 0) + 1;

            foreach ($candidate['sources'] as $s) {
                $sourceCounts[$s] = ($sourceCounts[$s] ?? 0) + 1;
            }
        }

        arsort($chains);

        return [
            'raw_count' => $rawCount,
            'candidates' => $candidates,
            'term_results' => $termResults,
            'chains' => $chains,
            'source_counts' => $sourceCounts,
        ];
    }

    /**
     * Deterministic pre-enrichment ranking. Reproducible (no rotation).
     * **Market cap is deliberately NOT used** — it is unknown before enrichment.
     *
     * Priority (all descending "goodness"):
     *   1. number of discovery sources
     *   2. boost signal present
     *   3. profile freshness (list position; "no profile" ranks last)
     *   4. search occurrence count
     *   5. token key, ascending — a total, stable tie-break
     *
     * @param  array<string,array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function prioritizeCandidates(array $candidates): array
    {
        $ordered = array_values($candidates);

        $freshness = static fn (array $c): int => $c['profile_rank'] === PHP_INT_MAX
            ? -1
            : PHP_INT_MAX - (int) $c['profile_rank'];

        usort($ordered, static function (array $a, array $b) use ($freshness): int {
            $score = static fn (array $c): array => [
                count($c['sources']),
                $c['boost'] ? 1 : 0,
                $freshness($c),
                $c['search_hits'],
            ];

            return ($score($b) <=> $score($a))
                ?: strcmp((string) $a['token_key'], (string) $b['token_key']);
        });

        return $ordered;
    }
}
