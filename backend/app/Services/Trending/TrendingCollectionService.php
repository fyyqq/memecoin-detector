<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\Token;
use App\Models\TrendingSnapshot;
use App\Services\DexScreener\DexScreenerClient;
use App\Services\DexScreener\DexScreenerNormalizer;
use App\Services\DexScreener\TokenObservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Near-real-time trending collection (runs every ~5 minutes).
 *
 *   /metas/trending/v1 -> /metas/meta/v1/{slug}   (documented APIs only)
 *     -> one representative candidate per (chain, token)
 *     -> DEDUPE
 *     -> MEMECOIN FILTER      (MemecoinClassifier — TRUE only)
 *     -> AGE FILTER <= 30d    (real earliest_pair_created_at; exclude if unknown)
 *     -> CURRENT MARKET FILTER (CURRENT market_cap in [$5M, $200M], liq > 0, vol > 0)
 *     -> score each ELIGIBLE token for 6h + 24h (TrackedTrendScorer — deterministic,
 *        no AI, market cap NOT a component)
 *     -> rank per timeframe -> trend_rank
 *     -> UPSERT trending_snapshots  (history — keyed on a 5-min capture bucket)
 *     -> UPSERT daily_trending_rankings  (the "trending yesterday" archive)
 *     -> recompute daily_chain_activity
 *
 * Only ELIGIBLE trending memecoins are scored + stored. The full provider
 * candidate universe is inspected internally but never persisted / ranked /
 * shown. The homepage returns only the top N (`config('trending.top_n')`).
 *
 * This step NEVER changes market-cap qualification, `observed_peak_market_cap`,
 * `historical_peak_value`, `qualification_events` or the risk logic. It does NOT
 * run historical qualification or risk screening — those keep their own cadence.
 * Trending never bypasses the safety filters: a trending token still has to pass
 * market-cap qualification AND the risk screen to reach the MAIN LIST.
 */
class TrendingCollectionService
{
    public function __construct(
        private readonly TrendingMetaCollector $collector,
        private readonly MemecoinClassifier $classifier,
        private readonly TrackedTrendScorer $scorer,
        private readonly TrendingSnapshotRecorder $recorder,
        private readonly DailyTrendingRollup $dailyRollup,
        private readonly ChainActivityRollup $chainActivityRollup,
        private readonly DexScreenerClient $client,
        private readonly DexScreenerNormalizer $normalizer,
        private readonly TokenObservationService $observations,
    ) {}

    public function collect(bool $force = false, ?CarbonImmutable $now = null): TrendingCollectionRunResult
    {
        $now ??= CarbonImmutable::now();
        $startedAt = microtime(true);
        $result = new TrendingCollectionRunResult;

        $intervalSeconds = max(60, (int) config('trending.refresh_minutes', 5) * 60);
        $captureBucket = intdiv($now->getTimestamp(), $intervalSeconds) * $intervalSeconds;
        $result->captureBucket = $captureBucket;

        ['candidates' => $candidates, 'diagnostics' => $metaDiag] = $this->collector->collect($now);
        $result->metaCount = (int) $metaDiag['meta_count'];
        $result->pairsSeen = (int) $metaDiag['pairs_seen'];
        $result->uniqueTokens = (int) $metaDiag['unique_tokens'];

        if ($candidates === []) {
            Log::info('Trending collection: no candidates', $result->toArray());
            $result->durationSeconds = round(microtime(true) - $startedAt, 2);

            return $result;
        }

        $minMc = (float) config('trending.eligibility.min_current_market_cap');
        $maxMc = (float) config('trending.eligibility.max_current_market_cap');
        $maxAgeDays = (int) config('trending.eligibility.max_age_days');

        // 1. MEMECOIN FILTER (cheap — name / symbol / meta narrative).
        /** @var array<string,string> $verdictByKey */
        $verdictByKey = [];
        $memeCandidates = [];
        foreach ($candidates as $candidate) {
            $verdict = $this->classifier->classify($candidate);
            $verdictByKey[$candidate->key()] = $verdict;

            if ($this->classifier->isEligibleForTrending($verdict)) {
                $memeCandidates[] = $candidate;
            } elseif ($verdict === MemecoinClassifier::FALSE) {
                $result->excludedNonMemecoin++;
            } else {
                $result->excludedAmbiguousMemecoin++;
            }
        }

        // 2. CURRENT MARKET FILTER (cheap — on the meta market data).
        $marketFiltered = [];
        foreach ($memeCandidates as $candidate) {
            if ($candidate->marketCap === null || $candidate->marketCap < $minMc || $candidate->marketCap > $maxMc) {
                $result->excludedCurrentMarketCap++;

                continue;
            }
            if (($candidate->liquidityUsd ?? 0.0) <= 0.0) {
                $result->excludedNoLiquidity++;

                continue;
            }
            if (($candidate->volume6h ?? 0.0) <= 0.0 && ($candidate->volume24h ?? 0.0) <= 0.0) {
                $result->excludedNoVolume++;

                continue;
            }
            $marketFiltered[] = $candidate;
        }

        // 3. Existing Token id + real earliest_pair_created_at.
        [$tokenIdByKey, $createdAtByKey] = $this->existingTokenData($marketFiltered);

        // 4. Enrich the (now small) set of market-filtered new memecoins so we
        //    can establish a real earliest_pair_created_at + a Token row.
        $this->enrichNewTokens($marketFiltered, $tokenIdByKey, $createdAtByKey, $now, $result);

        // 5. STRICT AGE FILTER — <= max_age_days on the REAL earliest pool date.
        //    If age is unknown, exclude (do not guess).
        /** @var list<array{candidate:TrendingCandidate,created_at:CarbonImmutable,age_days:float}> $eligible */
        $eligible = [];
        foreach ($marketFiltered as $candidate) {
            $createdAt = $createdAtByKey[$candidate->key()] ?? null;
            if ($createdAt === null) {
                $result->excludedAgeUnknown++;

                continue;
            }
            $ageDays = ($now->getTimestamp() - $createdAt->getTimestamp()) / 86_400.0;
            if ($ageDays > $maxAgeDays) {
                $result->excludedTooOld++;

                continue;
            }
            $eligible[] = ['candidate' => $candidate, 'created_at' => $createdAt, 'age_days' => round($ageDays, 3)];
        }

        $result->eligibleCandidates = count($eligible);

        if ($eligible === []) {
            $this->recomputeChainActivity($now, $result);
            $result->durationSeconds = round(microtime(true) - $startedAt, 2);
            Log::info('Trending collection completed (no eligible memecoins)', $result->toArray());

            return $result;
        }

        // 6. SCORE + RANK per timeframe — ELIGIBLE only. Store the whole eligible
        //    set (capped) so the chain filter + history have headroom; the API
        //    returns only top_n.
        $window = max(1, (int) config('trending.persistence.window_captures', 12));
        $eligibleCandidates = array_map(static fn (array $e): TrendingCandidate => $e['candidate'], $eligible);
        $priorAppearances = $this->priorAppearances($eligibleCandidates, $captureBucket, $window, $intervalSeconds);
        $createdAtLookup = [];
        foreach ($eligible as $e) {
            $createdAtLookup[$e['candidate']->key()] = $e['created_at'];
        }

        $maxPerTimeframe = max(1, (int) config('trending.collect.max_candidates_per_timeframe', 60));

        foreach (TrendingSnapshot::TIMEFRAMES as $timeframe) {
            $scored = [];
            foreach ($eligible as $entry) {
                /** @var TrendingCandidate $candidate */
                $candidate = $entry['candidate'];

                // Per-timeframe volume must be > 0 for this timeframe.
                if (($candidate->volumeFor($timeframe) ?? 0.0) <= 0.0) {
                    continue;
                }

                $prior = $priorAppearances[$candidate->key().'|'.$timeframe] ?? 0;
                $appearances = $prior + 1;

                $score = $this->scorer->score(new TrackedTrendInputs(
                    timeframe: $timeframe,
                    priceChangePct: $candidate->priceChangeFor($timeframe),
                    volumeUsd: $candidate->volumeFor($timeframe),
                    transactionCount: $candidate->txnsFor($timeframe),
                    liquidityUsd: $candidate->liquidityUsd,
                    appearances: $appearances,
                    persistenceWindow: $window,
                ));

                $scored[] = ['candidate' => $candidate, 'score' => $score, 'appearances' => $appearances];
            }

            // Deterministic: score desc, then token key asc.
            usort($scored, static function (array $a, array $b): int {
                return [$b['score']->score, $a['candidate']->key()] <=> [$a['score']->score, $b['candidate']->key()];
            });

            $scored = array_slice($scored, 0, $maxPerTimeframe);
            $result->candidatesPerTimeframe[$timeframe] = count($scored);

            $rank = 1;
            foreach ($scored as $row) {
                /** @var TrendingCandidate $candidate */
                $candidate = $row['candidate'];
                $verdict = $verdictByKey[$candidate->key()] ?? MemecoinClassifier::TRUE;
                $createdAt = $createdAtLookup[$candidate->key()] ?? null;

                DB::transaction(function () use ($candidate, $timeframe, $captureBucket, $rank, $row, $tokenIdByKey, $now, $verdict, $createdAt): void {
                    $this->recorder->record($candidate, $timeframe, $captureBucket, $rank, $row['score'], $row['appearances'], $tokenIdByKey, $now, $verdict, $createdAt);
                    $this->dailyRollup->record($candidate, $timeframe, $rank, $row['score']->score, $tokenIdByKey, $now);
                });

                $result->snapshotsWritten++;
                $result->dailyRankingsUpserted++;
                $rank++;
            }
        }

        $this->recomputeChainActivity($now, $result);

        foreach ($eligibleCandidates as $candidate) {
            $result->chainsSeen[$candidate->chainId] = ($result->chainsSeen[$candidate->chainId] ?? 0) + 1;
        }
        arsort($result->chainsSeen);

        $result->durationSeconds = round(microtime(true) - $startedAt, 2);
        Log::info('Trending collection completed', $result->toArray());

        return $result;
    }

    private function recomputeChainActivity(CarbonImmutable $now, TrendingCollectionRunResult $result): void
    {
        try {
            $result->chainActivityRowsWritten = $this->chainActivityRollup->recompute($now);
        } catch (Throwable $e) {
            Log::warning('Trending collection: chain-activity rollup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Existing Token id + real `earliest_pair_created_at` for a candidate set.
     *
     * @param  list<TrendingCandidate>  $candidates
     * @return array{0:array<string,int>,1:array<string,CarbonImmutable>} [tokenIdByKey, createdAtByKey]
     */
    private function existingTokenData(array $candidates): array
    {
        $byChain = [];
        foreach ($candidates as $c) {
            $byChain[$c->chainId][] = mb_strtolower($c->tokenAddress);
        }

        $tokenIdByKey = [];
        $createdAtByKey = [];
        foreach ($byChain as $chain => $addresses) {
            Token::query()
                ->where('chain_id', $chain)
                ->where(function ($q) use ($addresses): void {
                    $q->whereIn('token_address', $addresses)
                        ->orWhereIn(DB::raw('lower(token_address)'), $addresses);
                })
                ->get(['id', 'chain_id', 'token_address', 'earliest_pair_created_at'])
                ->each(function (Token $t) use (&$tokenIdByKey, &$createdAtByKey): void {
                    $key = mb_strtolower($t->chain_id).':'.mb_strtolower($t->token_address);
                    $tokenIdByKey[$key] = $t->id;
                    if ($t->earliest_pair_created_at !== null) {
                        $createdAtByKey[$key] = $t->earliest_pair_created_at;
                    }
                });
        }

        return [$tokenIdByKey, $createdAtByKey];
    }

    /**
     * Distinct prior capture buckets per (token, timeframe) inside the
     * persistence window — the persistence-component input.
     *
     * @param  list<TrendingCandidate>  $candidates
     * @return array<string,int> "chain:addr|timeframe" => count
     */
    private function priorAppearances(array $candidates, int $captureBucket, int $window, int $intervalSeconds): array
    {
        if ($candidates === []) {
            return [];
        }

        $windowStart = $captureBucket - ($window * $intervalSeconds);

        $rows = TrendingSnapshot::query()
            ->selectRaw('lower(chain_id) as c, lower(token_address) as a, timeframe, count(*) as n')
            ->where('capture_bucket', '>=', $windowStart)
            ->where('capture_bucket', '<', $captureBucket)
            ->groupBy('c', 'a', 'timeframe')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->c.':'.$row->a.'|'.$row->timeframe] = (int) $row->n;
        }

        return $map;
    }

    /**
     * Enrich market-filtered trending memecoins we do not yet track into a
     * Token + MarketSnapshot, reusing the discovery normalizer + observation
     * service — ONLY so we can establish a real `earliest_pair_created_at`.
     * Bounded per run. A token that fails the strict age gate here is NOT
     * persisted as a Token (and stays out of Trending Now).
     *
     * @param  list<TrendingCandidate>  $marketFiltered
     * @param  array<string,int>  $tokenIdByKey  mutated in place
     * @param  array<string,CarbonImmutable>  $createdAtByKey  mutated in place
     */
    private function enrichNewTokens(array $marketFiltered, array &$tokenIdByKey, array &$createdAtByKey, CarbonImmutable $now, TrendingCollectionRunResult $result): void
    {
        $maxEnrich = max(0, (int) config('trending.collect.max_new_token_enrich', 40));
        if ($maxEnrich === 0) {
            return;
        }

        $maxAgeDays = (int) config('trending.eligibility.max_age_days');
        $prefilterAgeDays = (int) config('trending.eligibility.enrich_prefilter_max_age_days', 35);

        $new = [];
        foreach ($marketFiltered as $c) {
            if (isset($tokenIdByKey[$c->key()])) {
                continue;
            }
            if ($c->pairAddress === null) {
                continue;
            }
            // Loose single-pair age pre-gate — skip enriching a clearly-old token.
            $ageHours = $c->pairAgeHours($now);
            if ($ageHours !== null && $ageHours > $prefilterAgeDays * 24) {
                continue;
            }
            $new[] = $c;
        }

        usort($new, static fn (TrendingCandidate $a, TrendingCandidate $b): int => strcmp($a->key(), $b->key()));
        $new = array_slice($new, 0, $maxEnrich);

        if ($new === []) {
            return;
        }

        $result->enrichAttempted = count($new);

        $pairsByToken = $this->client->tokenPairsBatch(array_map(
            static fn (TrendingCandidate $c): array => ['chain_id' => $c->chainId, 'token_address' => $c->tokenAddress],
            $new,
        ));

        foreach ($new as $candidate) {
            $key = mb_strtolower($candidate->chainId).':'.mb_strtolower($candidate->tokenAddress);
            $pairs = $pairsByToken[$key] ?? [];

            $dto = $this->normalizer->normalize(
                $candidate->chainId,
                $candidate->tokenAddress,
                $pairs,
                ['trending_meta'],
                $now,
                [
                    'trending_meta_slug' => $candidate->trendingMetaSlug,
                    'trending_meta_name' => $candidate->trendingMetaName,
                    'trending_meta_count' => $candidate->metaCount(),
                ],
            );

            if ($dto === null || $dto->earliestPairCreatedAt === null || $dto->ageDays === null || $dto->ageDays > $maxAgeDays) {
                continue;
            }

            try {
                $observation = $this->observations->record($dto, $now);
            } catch (Throwable $e) {
                $result->providerFailures++;
                Log::warning('Trending collection: failed to persist new token', ['token' => $dto->tokenKey, 'error' => $e->getMessage()]);

                continue;
            }

            $tokenIdByKey[$candidate->key()] = $observation->token->id;
            $createdAtByKey[$candidate->key()] = $dto->earliestPairCreatedAt;
            $result->newTokensEnriched++;
        }
    }
}
