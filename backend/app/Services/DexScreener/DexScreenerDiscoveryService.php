<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\QualifiedCandidate;
use App\DTOs\DexScreener\TokenCandidateData;
use App\Models\HistoricalPeakEvidence;
use App\Models\IngestionRun;
use App\Models\Token;
use App\Models\TrendingSnapshot;
use App\Services\Historical\HistoricalQualificationService;
use App\Services\Historical\QualificationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sprint 1 discovery pipeline (Step 19 — trending-meta-first):
 *
 *   DISCOVER → PRE-FILTER (trending-meta market data) → PRIORITIZE → ENRICH
 *   → NORMALIZE → AGE FILTER → CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP
 *   → QUALIFICATION → PERSIST EVIDENCE → RETURN
 *
 * Discovery priority:
 *   1. Trending Meta — `GET /metas/trending/v1` → `GET /metas/meta/v1/{slug}`
 *      (documented; the ONLY primary source).
 *   2. Latest token profiles.
 *   3. Latest / top boosts.
 *   4. Keyword search — SUPPLEMENTAL fallback, OFF by default.
 *
 * DexScreener's real per-pair Trending table (io.dexscreener.com WebSocket) is
 * undocumented + Cloudflare-walled and is deliberately NOT used — see
 * docs/trending-discovery-reconnaissance.md.
 *
 * A token qualifies for the main list when age <= 30 days AND a VERIFIED /
 * OBSERVED market-cap peak sits in [$5M, $200M] — proven by CURRENT_OBSERVATION
 * (our own snapshot) or HISTORICAL_VERIFIED (CoinGecko). HISTORICAL_ESTIMATE
 * (FDV basis) and UNKNOWN do NOT qualify. A token whose CURRENT MC has dumped
 * below $5M STAYS qualified if an earlier observation / historical evidence
 * already cleared the floor.
 *
 * See docs/sprint-1-discovery.md.
 */
class DexScreenerDiscoveryService
{
    public function __construct(
        private readonly DexScreenerClient $client,
        private readonly DexScreenerNormalizer $normalizer,
        private readonly TokenObservationService $observations,
        private readonly HistoricalQualificationService $historical,
        private readonly QualificationEventRecorder $qualificationEvents,
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
            // Step 19 trending-meta coverage.
            'trending_meta_count' => $diagnostics['trending_meta_count'],
            'trending_meta_pairs_seen' => $diagnostics['trending_meta_pairs_seen'],
            'trending_meta_unique_candidates' => $diagnostics['trending_meta_unique_candidates'],
            'pre_filtered_candidates' => $diagnostics['pre_filtered_candidates'],
            'discovery_source_counts' => $diagnostics['discovery_source_counts'],
            'trending_meta_slugs_used' => $diagnostics['trending_meta_slugs_used'],
        ]);

        Log::info('Memecoin discovery run complete', ['ingestion_run_id' => $run->id] + $diagnostics);

        return new DiscoveryResult($final, $diagnostics, $notQualifiedSample, $run->id);
    }

    /**
     * The discovery pipeline itself. Returns
     * `[list<QualifiedCandidate>, array diagnostics, list<array> notQualifiedSample]`.
     *
     * @return array{0:list<QualifiedCandidate>,1:array<string,mixed>,2:list<array<string,mixed>>}
     */
    private function runPipeline(?string $chain, ?int $limit): array
    {
        $now = CarbonImmutable::now();
        $chain = $chain !== null ? mb_strtolower(trim($chain)) : null;

        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $peakMax = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');
        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $maxEnrich = (int) config('dexscreener.limits.max_candidates_to_enrich');
        $candidateCap = (int) config('dexscreener.limits.discovery_candidate_cap');
        $limit ??= (int) config('dexscreener.limits.default_result_limit');

        $trendingMetaEnabled = (bool) config('dexscreener.discovery_sources.trending_meta_enabled', true);
        $profilesEnabled = (bool) config('dexscreener.discovery_sources.profiles_enabled', true);
        $boostsEnabled = (bool) config('dexscreener.discovery_sources.boosts_enabled', true);
        $keywordEnabled = (bool) config('dexscreener.discovery_sources.keyword_enabled', false);

        // 1. DISCOVER --------------------------------------------------------
        // Keyword search is a fallback only; its term plan is built (and the
        // /metas/trending/v1 call it may make) only when it is enabled.
        $termPlan = $keywordEnabled
            ? $this->searchTerms->build()
            : ['terms' => [], 'categories' => ['core' => 0, 'meta_slug' => 0, 'meta_name' => 0, 'ecosystem' => 0], 'budget' => 0, 'meta_terms_considered' => 0];

        $discovery = $this->collectCandidates($chain, $termPlan['terms'], [
            'trending_meta' => $trendingMetaEnabled,
            'profiles' => $profilesEnabled,
            'boosts' => $boostsEnabled,
            'keyword' => $keywordEnabled,
        ], $peakMax);

        $candidates = $discovery['candidates'];
        $termResults = $discovery['term_results'];

        $diagnostics = [
            'raw_discovery_candidates' => $discovery['raw_count'],
            'unique_candidates' => count($candidates),
            'pre_filtered_candidates' => count($candidates),
            'candidates_after_chain_filter' => count($candidates),
            'discovery_source_counts' => $discovery['source_counts'],
            'chains_discovered' => $discovery['chains'],

            // Step 19 — trending-meta coverage.
            'trending_meta_enabled' => $trendingMetaEnabled,
            'trending_meta_count' => $discovery['trending']['meta_count'],
            'trending_meta_slugs_used' => $discovery['trending']['slugs_used'],
            'trending_meta_pairs_seen' => $discovery['trending']['pairs_seen'],
            'trending_meta_prefilter_dropped' => $discovery['trending']['prefilter_dropped'],
            'trending_meta_prefilter_reasons' => $discovery['trending']['prefilter_reasons'],
            'trending_meta_ad_or_malformed_skipped' => $discovery['trending']['ad_or_malformed_skipped'],
            'trending_meta_unique_candidates' => $discovery['source_counts']['trending_meta'] ?? 0,
            'trending_meta_tokens_unique' => $discovery['source_counts']['trending_meta'] ?? 0,

            // Keyword fallback.
            'keyword_discovery_enabled' => $keywordEnabled,
            'search_terms_used' => count($termResults),
            'search_terms_with_results' => count(array_filter($termResults, static fn (int $n): bool => $n > 0)),
            'search_terms_empty' => count(array_filter($termResults, static fn (int $n): bool => $n === 0)),
            'search_term_categories' => $termPlan['categories'],
            'search_term_budget' => $termPlan['budget'],

            'discovery_candidate_cap' => $candidateCap,
            'candidate_cap_dropped' => 0,
            'candidates_considered' => 0,
            'selected_for_enrichment' => 0,
            'deferred_candidates' => 0,
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
            'not_qualified_fdv_estimate_only' => 0,
            // Step 20 — "$5M crossing" events.
            'qualification_events_created' => 0,
            'qualification_events_existing' => 0,
            // Step 19 — cleared the floor but peaked above the $200M ceiling.
            'not_qualified_peak_above_ceiling' => 0,
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
        // Market cap is NOT a prioritization signal. Recent trend rank /
        // appearances (from the near-real-time trending collector) rank a
        // candidate ABOVE profile / boost / keyword.
        $ordered = $this->prioritizeCandidates($candidates, $this->recentTrendSignals(array_keys($candidates)));

        $diagnostics['candidate_cap_dropped'] = max(0, count($ordered) - $candidateCap);
        $considered = array_slice($ordered, 0, max(1, $candidateCap));
        $diagnostics['candidates_considered'] = count($considered);

        $toEnrich = array_slice($considered, 0, max(0, $maxEnrich));
        $deferred = count($considered) - count($toEnrich);
        $diagnostics['selected_for_enrichment'] = count($toEnrich);
        $diagnostics['deferred_candidates'] = $deferred;
        $diagnostics['enrichment_deferred'] = $deferred;
        $diagnostics['enrichment_attempted'] = count($toEnrich);

        // 2. ENRICH (bounded concurrent batch) + 3. NORMALIZE -------------
        // Trending-meta candidates already carry market data, but token-level
        // enrichment is still required for the all-pair earliest_pair_created_at,
        // the representative pair, and identity normalization.
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
                $this->discoveryContext($candidate),
            );

            if ($dto === null) {
                $diagnostics['enrichment_failed']++;

                continue;
            }

            $diagnostics['enriched_ok']++;
            $normalized[] = $dto;
        }

        // 4. AGE FILTER → PERSIST TOKEN + SNAPSHOT + OBSERVED PEAK ---------
        // FINAL age validation — always uses earliest_pair_created_at across ALL
        // of the token's pairs, never a single meta pair's pairCreatedAt.
        /** @var list<array{token:Token,dto:TokenCandidateData,observation:RecordedObservation}> $ageEligible */
        $ageEligible = [];

        foreach ($normalized as $dto) {
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

        // 6. QUALIFICATION — $5M <= qualifying peak <= $200M ------------------
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

            if ($evidence !== null && $evidence->qualifies($peakMin, $peakMax)) {
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
            $aboveCeiling = $evidence !== null && $evidence->peakAboveCeiling($peakMin, $peakMax);

            if ($aboveCeiling) {
                $diagnostics['not_qualified_peak_above_ceiling']++;
            } elseif ($estimateOnly) {
                $diagnostics['not_qualified_fdv_estimate_only']++;
            } elseif ($peak !== null) {
                $diagnostics['observed_peak_below_threshold']++;
            }

            if (count($notQualifiedSample) < $sampleCap) {
                $notQualifiedSample[] = [
                    'token_key' => $dto->tokenKey,
                    'chain_id' => $dto->chainId,
                    'symbol' => $dto->symbol,
                    'reason' => match (true) {
                        $aboveCeiling => 'qualifying_peak_above_ceiling',
                        $estimateOnly => 'historical_fdv_estimate_only',
                        $peak === null => 'insufficient_historical_observation',
                        default => 'observed_peak_below_threshold',
                    },
                    'historical_peak_status' => $status,
                    'current_market_cap' => $dto->marketCap,
                    'fdv' => $dto->fdv,
                    'observed_peak_market_cap' => $peak,
                    'qualification_peak_value' => $evidence?->peak_value_usd,
                    'age_days' => $dto->ageDays,
                    'sources' => $dto->sources,
                ];
            }
        }

        // 6b. QUALIFICATION EVENTS (Step 20) — record a "$5M crossing" for every
        // token whose evidence proves a VERIFIED / OBSERVED crossing of the
        // floor. Idempotent; the $200M ceiling is a list concern, not a crossing
        // concern (a token keeps its historical record either way).
        $eventStats = $this->qualificationEvents->recordBatch(
            array_map(
                static fn (array $e): array => [
                    'token' => $e['token'],
                    'evidence' => $evidenceByToken[$e['token']->id] ?? null,
                ],
                $ageEligible,
            ),
            $now,
            $peakMin,
            $peakMax,
        );
        foreach ($eventStats as $statKey => $statValue) {
            $diagnostics[$statKey] = $statValue;
        }

        // Highest qualifying peak first, then youngest.
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
     * Collect + de-duplicate raw discovery hits across all enabled sources.
     *
     * Trending-meta pairs are PRE-FILTERED here (before the expensive
     * /token-pairs enrichment) on the market data the meta response already
     * carries: marketCap present & > 0 & <= ceiling, volume.h24 > 0,
     * liquidity.usd > 0, pairCreatedAt present, loose pair age <= 35 days. The
     * $5M lower bound is NOT applied — it is a qualification-step peak rule.
     *
     * @param  list<string>  $terms  keyword-search terms (empty unless keyword discovery is enabled)
     * @param  array{trending_meta:bool,profiles:bool,boosts:bool,keyword:bool}  $enabled
     * @return array{
     *     raw_count:int,
     *     candidates:array<string,array<string,mixed>>,
     *     term_results:array<string,int>,
     *     chains:array<string,int>,
     *     source_counts:array<string,int>,
     *     trending:array{meta_count:int,slugs_used:list<string>,pairs_seen:int,prefilter_dropped:int,prefilter_reasons:array<string,int>,ad_or_malformed_skipped:int}
     * }
     */
    private function collectCandidates(?string $chain, array $terms, array $enabled, float $peakMax): array
    {
        /** @var array<string,array<string,mixed>> $candidates */
        $candidates = [];
        $rawCount = 0;
        $order = 0;

        $add = function (?string $chainId, ?string $tokenAddress, string $source, array $opts = []) use (&$candidates, &$rawCount, &$order, $chain): void {
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
                    // slug => name of every trending meta that surfaced the token.
                    'trending_metas' => [],
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
                    (int) ($opts['profile_rank'] ?? PHP_INT_MAX),
                ),
                'trending_meta' => isset($opts['meta_slug'])
                    ? $candidates[$key]['trending_metas'][(string) $opts['meta_slug']] = (string) ($opts['meta_name'] ?? $opts['meta_slug'])
                    : null,
                default => null,
            };
        };

        // A. TRENDING META (primary) — /metas/trending/v1 → /metas/meta/v1/{slug}.
        $trending = [
            'meta_count' => 0,
            'slugs_used' => [],
            'pairs_seen' => 0,
            'prefilter_dropped' => 0,
            'prefilter_reasons' => [],
            'ad_or_malformed_skipped' => 0,
        ];

        if ($enabled['trending_meta']) {
            $metaLimit = max(0, (int) config('dexscreener.discovery_sources.trending_meta_limit', 18));
            $prefilterMaxAgeDays = (int) config('dexscreener.filters.prefilter_max_age_days', 35);
            $now = CarbonImmutable::now();

            $selected = [];
            foreach ($this->client->trendingMetas() as $meta) {
                if (count($selected) >= $metaLimit) {
                    break;
                }
                $slug = is_array($meta) && is_string($meta['slug'] ?? null) ? trim($meta['slug']) : '';
                if ($slug === '') {
                    continue;
                }
                $selected[$slug] = is_string($meta['name'] ?? null) ? trim($meta['name']) : $slug;
            }

            $trending['meta_count'] = count($selected);
            $trending['slugs_used'] = array_keys($selected);

            foreach ($selected as $slug => $listName) {
                $detail = $this->client->metaBySlug($slug);
                $metaName = is_string($detail['name'] ?? null) && $detail['name'] !== '' ? $detail['name'] : $listName;
                $pairs = is_array($detail['pairs'] ?? null) ? $detail['pairs'] : [];

                foreach ($pairs as $pair) {
                    if (! is_array($pair)) {
                        continue;
                    }
                    $trending['pairs_seen']++;

                    $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
                    $chainId = is_string($pair['chainId'] ?? null) ? $pair['chainId'] : null;
                    $addr = is_string($base['address'] ?? null) ? $base['address'] : null;
                    $pairAddress = $pair['pairAddress'] ?? null;

                    // Defensive: the documented /metas/meta response never carries
                    // the narrative-bar ad, but reject anything that is not a
                    // real member pair so paid placement can never leak in.
                    if ($chainId === null || $addr === null || ! is_string($pairAddress) || $pairAddress === '') {
                        $trending['ad_or_malformed_skipped']++;

                        continue;
                    }

                    $reason = $this->prefilterReason($pair, $now, $peakMax, $prefilterMaxAgeDays);
                    if ($reason !== null) {
                        $trending['prefilter_dropped']++;
                        $trending['prefilter_reasons'][$reason] = ($trending['prefilter_reasons'][$reason] ?? 0) + 1;

                        continue;
                    }

                    $add($chainId, $addr, 'trending_meta', ['meta_slug' => $slug, 'meta_name' => $metaName]);
                }
            }
        }

        // B. LATEST TOKEN PROFILES — list position is a freshness signal.
        if ($enabled['profiles']) {
            foreach (array_values($this->client->latestTokenProfiles()) as $i => $row) {
                $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'profile', ['profile_rank' => $i]);
            }
        }

        // C. LATEST + TOP BOOSTS (paid). Never allowed to out-priority trending meta.
        if ($enabled['boosts']) {
            foreach ($this->client->latestTokenBoosts() as $row) {
                $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
            }
            foreach ($this->client->topTokenBoosts() as $row) {
                $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
            }
        }

        // D. KEYWORD SEARCH — supplemental fallback only.
        /** @var array<string,int> $termResults */
        $termResults = [];
        if ($enabled['keyword']) {
            foreach ($terms as $term) {
                $pairs = $this->client->search($term);
                $termResults[$term] = count($pairs);

                foreach ($pairs as $pair) {
                    $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
                    $add($pair['chainId'] ?? null, $base['address'] ?? null, 'search');
                }
            }
        }

        // Coverage aggregates over the UNIQUE candidate set.
        $chains = [];
        $sourceCounts = ['trending_meta' => 0, 'profile' => 0, 'boost' => 0, 'search' => 0];

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
            'trending' => $trending,
        ];
    }

    /**
     * Pre-filter one trending-meta pair on the market data it already carries.
     * Returns a drop reason, or null if it survives. The $5M lower bound is
     * deliberately NOT checked here.
     *
     * @param  array<string,mixed>  $pair
     */
    private function prefilterReason(array $pair, CarbonImmutable $now, float $peakMax, int $maxAgeDays): ?string
    {
        $marketCap = $this->floatOrNull($pair['marketCap'] ?? null);
        if ($marketCap === null || $marketCap <= 0.0) {
            return 'market_cap_missing_or_zero';
        }
        if ($marketCap > $peakMax) {
            // Current MC alone already exceeds the ceiling — the peak can only be
            // higher, so it can never satisfy the max-peak rule.
            return 'market_cap_above_ceiling';
        }

        $liquidity = is_array($pair['liquidity'] ?? null) ? $pair['liquidity'] : [];
        if (($this->floatOrNull($liquidity['usd'] ?? null) ?? 0.0) <= 0.0) {
            return 'liquidity_zero';
        }

        $volume = is_array($pair['volume'] ?? null) ? $pair['volume'] : [];
        if (($this->floatOrNull($volume['h24'] ?? null) ?? 0.0) <= 0.0) {
            return 'volume_zero';
        }

        $createdMs = $this->intOrNull($pair['pairCreatedAt'] ?? null);
        if ($createdMs === null || $createdMs <= 0) {
            return 'pair_created_at_missing';
        }

        $ageDays = ($now->getTimestampMs() - $createdMs) / 86_400_000;
        if ($ageDays > $maxAgeDays) {
            return 'loose_age_exceeded';
        }

        return null;
    }

    /**
     * Small provenance block for a candidate surfaced by a trending meta.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>|null
     */
    private function discoveryContext(array $candidate): ?array
    {
        /** @var array<string,string> $metas */
        $metas = $candidate['trending_metas'] ?? [];

        if ($metas === []) {
            return null;
        }

        $slug = array_key_first($metas);

        return [
            'trending_meta_slug' => $slug,
            'trending_meta_name' => $metas[$slug],
            'trending_meta_count' => count($metas),
        ];
    }

    /**
     * Deterministic pre-enrichment ranking (Step 19 + Trending Tracking).
     * Reproducible (no randomness / no wall-clock). **Market cap is not a
     * signal.** Priority, all descending "goodness":
     *
     *   1. surfaced by a trending meta at all
     *   2. recent trend-rank quality (from the near-real-time trending
     *      collector — lower `trend_rank` is better, inverted here)
     *   3. recent trend appearances (persistence)
     *   4. number of distinct trending metas that surfaced it (multi-meta)
     *   5. profile signal present
     *   6. boost signal present
     *   7. keyword-search occurrence count
     *   8. profile freshness (list position) — a stable secondary tie-break
     *   9. token key, ascending — total, stable final tie-break
     *
     * @param  array<string,array<string,mixed>>  $candidates
     * @param  array<string,array{rank:int,appearances:int}>  $trendSignals  key => recent trend signal
     * @return list<array<string,mixed>>
     */
    private function prioritizeCandidates(array $candidates, array $trendSignals = []): array
    {
        $ordered = array_values($candidates);

        $freshness = static fn (array $c): int => ($c['profile_rank'] ?? PHP_INT_MAX) === PHP_INT_MAX
            ? -1
            : PHP_INT_MAX - (int) $c['profile_rank'];

        usort($ordered, static function (array $a, array $b) use ($freshness, $trendSignals): int {
            $score = static function (array $c) use ($trendSignals, $freshness): array {
                $signal = $trendSignals[$c['token_key']] ?? null;
                // Lower rank is better; invert so bigger = better. No signal -> 0.
                $rankQuality = $signal !== null ? max(0, 100_000 - $signal['rank']) : 0;

                return [
                    in_array('trending_meta', $c['sources'], true) ? 1 : 0,
                    $rankQuality,
                    $signal['appearances'] ?? 0,
                    count($c['trending_metas'] ?? []),
                    in_array('profile', $c['sources'], true) ? 1 : 0,
                    $c['boost'] ? 1 : 0,
                    $c['search_hits'],
                    $freshness($c),
                ];
            };

            return ($score($b) <=> $score($a))
                ?: strcmp((string) $a['token_key'], (string) $b['token_key']);
        });

        return $ordered;
    }

    /**
     * The most recent trend signal (best rank, max appearances across 6h/24h)
     * for a set of candidate token keys, from the latest `trending_snapshots`
     * capture. One query, no N+1. Empty when the trending collector has not run.
     *
     * @param  list<string>  $keys  "chain:addr" (lowercased)
     * @return array<string,array{rank:int,appearances:int}>
     */
    private function recentTrendSignals(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $latestBucket = TrendingSnapshot::query()->max('capture_bucket');
        if ($latestBucket === null) {
            return [];
        }

        $rows = TrendingSnapshot::query()
            ->where('capture_bucket', (int) $latestBucket)
            ->selectRaw("lower(chain_id) || ':' || lower(token_address) as k, min(trend_rank) as rank, max(trend_appearances) as appearances")
            ->groupBy('k')
            ->pluck('appearances', 'k');

        // Re-query for min rank keyed the same way (pluck only returns one value column).
        $ranks = TrendingSnapshot::query()
            ->where('capture_bucket', (int) $latestBucket)
            ->selectRaw("lower(chain_id) || ':' || lower(token_address) as k, min(trend_rank) as rank")
            ->groupBy('k')
            ->pluck('rank', 'k');

        $wanted = array_flip($keys);
        $signals = [];
        foreach ($ranks as $key => $rank) {
            if (isset($wanted[$key])) {
                $signals[$key] = ['rank' => (int) $rank, 'appearances' => (int) ($rows[$key] ?? 0)];
            }
        }

        return $signals;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return null;
    }
}
