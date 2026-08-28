<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\QualifiedCandidate;
use App\DTOs\DexScreener\TokenCandidateData;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sprint 1 discovery pipeline:
 *
 *   DISCOVER → ENRICH → NORMALIZE → AGE FILTER → PERSIST TOKEN + SNAPSHOT
 *   → UPDATE OBSERVED PEAK → QUALIFY BY OBSERVED PEAK → RETURN
 *
 * Eligibility = age <= 30 days AND observed_peak_market_cap >= threshold, where
 * "observed peak" is the highest market cap captured by OUR snapshots since we
 * first saw the token — not a guaranteed lifetime high. Current market cap may
 * be below the threshold and the token still qualifies.
 *
 * See docs/sprint-1-discovery.md.
 */
class DexScreenerDiscoveryService
{
    public function __construct(
        private readonly DexScreenerClient $client,
        private readonly DexScreenerNormalizer $normalizer,
        private readonly TokenObservationService $observations,
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
        ]);

        Log::info('Memecoin discovery run complete', ['ingestion_run_id' => $run->id] + $diagnostics);

        return new DiscoveryResult($final, $diagnostics, $notQualifiedSample, $run->id);
    }

    /**
     * The discovery pipeline itself (unchanged rules). Returns
     * `[list<QualifiedCandidate>, array<string,int> diagnostics, list<array> notQualifiedSample]`.
     *
     * @return array{0:list<QualifiedCandidate>,1:array<string,int>,2:list<array<string,mixed>>}
     */
    private function runPipeline(?string $chain, ?int $limit): array
    {
        $now = CarbonImmutable::now();
        $chain = $chain !== null ? mb_strtolower(trim($chain)) : null;

        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $maxEnrich = (int) config('dexscreener.limits.max_candidates_to_enrich');
        $limit ??= (int) config('dexscreener.limits.default_result_limit');

        // 1. DISCOVER ---------------------------------------------------------
        [$rawCount, $candidates] = $this->collectCandidates($chain);

        $diagnostics = [
            'raw_discovery_candidates' => $rawCount,
            'unique_candidates' => count($candidates),
            'candidates_after_chain_filter' => count($candidates),
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
            'returned' => 0,
        ];

        // Order candidates before applying the enrich cap:
        //  1. tokens seen from more than one source (a genuine signal), then
        //  2. tokens that are not *only* a paid profile hit (profile-only skews
        //     to brand-new sub-$100k tokens), then
        //  3. discovery order.
        $ordered = array_values($candidates);
        usort($ordered, function (array $a, array $b): int {
            $score = static fn (array $c): array => [
                count($c['sources']),
                $c['sources'] === ['profile'] ? 0 : 1,
            ];

            return $score($b) <=> $score($a);
        });

        // Every enriched, age-eligible candidate produces a stored observation,
        // so enrich up to the hard ceiling regardless of the result `limit`.
        $toEnrich = array_slice($ordered, 0, max(0, $maxEnrich));
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

        // 4. AGE FILTER → PERSIST → QUALIFY --------------------------------
        /** @var list<QualifiedCandidate> $qualified */
        $qualified = [];
        /** @var list<array<string,mixed>> $notQualifiedSample */
        $notQualifiedSample = [];
        $sampleCap = 50;

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

            $token = $observation->token;
            $peak = $token->observed_peak_market_cap;

            if ($peak !== null && $peak >= $peakMin) {
                $diagnostics['qualified']++;

                // Did THIS run's observation push the peak across the threshold?
                if ($observation->peakUpdated
                    && ($observation->previousObservedPeak === null || $observation->previousObservedPeak < $peakMin)) {
                    $diagnostics['qualified_from_current_observation']++;
                }

                $qualified[] = new QualifiedCandidate(
                    current: $dto,
                    observedPeakMarketCap: $peak,
                    observedPeakMarketCapAt: $token->observed_peak_market_cap_at,
                    observedSince: $token->first_observed_at ?? $now,
                );

                continue;
            }

            $diagnostics['not_qualified']++;

            if ($peak !== null) {
                $diagnostics['observed_peak_below_threshold']++;
            }

            if (count($notQualifiedSample) < $sampleCap) {
                $notQualifiedSample[] = [
                    'token_key' => $dto->tokenKey,
                    'chain_id' => $dto->chainId,
                    'symbol' => $dto->symbol,
                    // Never claims the token has never crossed the threshold.
                    'reason' => $peak === null
                        ? 'insufficient_historical_observation'
                        : 'observed_peak_below_threshold',
                    'current_market_cap' => $dto->marketCap,
                    'fdv' => $dto->fdv,
                    'observed_peak_market_cap' => $peak,
                    'age_days' => $dto->ageDays,
                ];
            }
        }

        // Highest observed peak first, then youngest.
        usort($qualified, function (QualifiedCandidate $a, QualifiedCandidate $b): int {
            return [$b->observedPeakMarketCap ?? 0.0, -($a->current->ageDays ?? PHP_INT_MAX)]
                <=> [$a->observedPeakMarketCap ?? 0.0, -($b->current->ageDays ?? PHP_INT_MAX)];
        });

        $final = array_slice($qualified, 0, max(0, $limit));
        $diagnostics['returned'] = count($final);

        return [$final, $diagnostics, $notQualifiedSample];
    }

    /**
     * Build the de-duplicated raw candidate set.
     *
     * @return array{0:int,1:array<string,array{chain_id:string,token_address:string,sources:list<string>}>}
     */
    private function collectCandidates(?string $chain): array
    {
        /** @var array<string,array{chain_id:string,token_address:string,sources:list<string>}> $candidates */
        $candidates = [];
        $rawCount = 0;

        $add = function (?string $chainId, ?string $tokenAddress, string $source) use (&$candidates, &$rawCount, $chain): void {
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
                    'sources' => [],
                ];
            }

            if (! in_array($source, $candidates[$key]['sources'], true)) {
                $candidates[$key]['sources'][] = $source;
            }
        };

        // A. latest token profiles
        foreach ($this->client->latestTokenProfiles() as $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'profile');
        }

        // B. latest token boosts + C. top token boosts
        foreach ($this->client->latestTokenBoosts() as $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
        }
        foreach ($this->client->topTokenBoosts() as $row) {
            $add($row['chainId'] ?? null, $row['tokenAddress'] ?? null, 'boost');
        }

        // D. trending metas → search terms only (aggregates, not tokens)
        // E. curated + meta-derived search terms
        foreach ($this->searchTerms() as $term) {
            foreach ($this->client->search($term) as $pair) {
                $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
                $add($pair['chainId'] ?? null, $base['address'] ?? null, 'search');
            }
        }

        return [$rawCount, $candidates];
    }

    /**
     * Curated terms from config, plus a few trending meta names/slugs.
     *
     * @return list<string>
     */
    private function searchTerms(): array
    {
        /** @var list<string> $curated */
        $curated = config('dexscreener.search_terms', []);

        $metaLimit = (int) config('dexscreener.trending_meta_terms', 0);
        $metaTerms = [];

        if ($metaLimit > 0) {
            foreach ($this->client->trendingMetas() as $meta) {
                foreach (['slug', 'name'] as $field) {
                    $value = is_string($meta[$field] ?? null) ? trim($meta[$field]) : '';

                    if ($value !== '') {
                        $metaTerms[] = $value;

                        break;
                    }
                }

                if (count($metaTerms) >= $metaLimit) {
                    break;
                }
            }
        }

        $terms = array_values(array_unique(array_map(
            'mb_strtolower',
            array_filter([...$curated, ...$metaTerms], fn ($t): bool => is_string($t) && trim($t) !== ''),
        )));

        return $terms;
    }
}
