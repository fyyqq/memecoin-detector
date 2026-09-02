<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Ranking\Providers\InternalObservedMonthlyResearchProvider;
use App\Services\Ranking\Providers\SeedFileMonthlyResearchProvider;
use App\Services\Ranking\Providers\WebMonthlyResearchProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Historical Monthly Backfill (Step 25 — Top 3).
 *
 * For a PAST completed month + chain bucket, rank the best-supported Top 3
 * performing memecoins from research evidence — we do NOT just return
 * "no result" because our MarketSnapshot history started in late August 2026.
 *
 * Flow, per bucket:
 *   1. gather candidates from the configured providers
 *      (`internal_observed` always; `seed_file` = operator-verified historical
 *      research; `web_research` = an OFF-by-default extension point);
 *   2. resolve entity identity (name + symbol + chain, ideally an address —
 *      never symbol alone);
 *   3. validate eligibility as far as the evidence allows: $5M–$200M MARKET CAP
 *      (never FDV), the right bucket, the right month, ≤ 30-day trading age
 *      (`age_uncertain` + lower confidence when the launch date is unknown);
 *   4. rank survivors (Top 3) with the deterministic participation formula
 *      ({@see MonthlyPerformanceCalculator::scoreHistorical});
 *   5. classify each ranked row:
 *        finalized           — a defensible ranked entry (an internal-observed
 *                              eligible token, an exact DexScreener rank from a
 *                              source, a fully-supported historical performer,
 *                              or a real lead on incomplete evidence at
 *                              `confidence: low`);
 *        no_verified_result  — no defensible candidate (a single rank-1 row,
 *                              `token_id` null).
 *
 * NEVER fabricates a candidate, a date, a source, a holder count or a
 * DexScreener rank. NEVER uses the current Risk Assessment. NEVER uses AI.
 * Invoked ONLY by `memecoins:research-monthly-champions` — never the read API,
 * never the daily finalize pass.
 */
class MonthlyChampionResearchService
{
    private const MIN_MC_USD = 5_000_000.0;

    private const MAX_MC_USD = 200_000_000.0;

    /** @var list<MonthlyChampionResearchProvider> */
    private array $providers;

    public function __construct(
        private readonly MonthlyChampionService $champions,
        private readonly MonthlyPerformanceCalculator $calculator,
    ) {
        $enabled = array_map('trim', (array) config('ranking.research.providers', ['internal_observed', 'seed_file']));

        $all = [
            'internal_observed' => fn () => app(InternalObservedMonthlyResearchProvider::class),
            'internal' => fn () => app(InternalObservedMonthlyResearchProvider::class), // alias
            'seed_file' => fn () => app(SeedFileMonthlyResearchProvider::class),
            'web_research' => fn () => app(WebMonthlyResearchProvider::class),
        ];

        $this->providers = [];
        foreach ($enabled as $name) {
            if (isset($all[$name])) {
                $provider = ($all[$name])();
                // De-dupe (internal_observed / internal alias).
                if (! in_array($provider, $this->providers, true)) {
                    $this->providers[] = $provider;
                }
            }
        }
    }

    /**
     * @param  (callable(string $bucket, string $phase, ?MonthlyRanking $row): void)|null  $progress
     */
    public function research(
        int $year,
        int $month,
        ?string $onlyBucket,
        bool $force,
        ?CarbonImmutable $now = null,
        ?callable $progress = null,
    ): MonthlyChampionResearchRunResult {
        $now ??= CarbonImmutable::now();
        $startedAt = microtime(true);
        $window = MonthWindow::of($year, $month);

        if ($window->isCurrent($now) && ! $force) {
            throw new \InvalidArgumentException(
                sprintf('%s %d is the current month — historical backfill needs --force (it uses internal live observations otherwise).', $window->monthName(), $year),
            );
        }

        $buckets = $onlyBucket !== null ? [$onlyBucket] : ChainBucket::ALL;
        $maxBuckets = max(1, (int) config('ranking.research.max_buckets_per_run', 15));
        $buckets = array_slice($buckets, 0, $maxBuckets);

        $touched = [];
        $counts = ['finalized' => 0, 'best_supported' => 0, 'no_verified' => 0, 'skipped' => 0, 'future' => 0];
        $providerFailures = 0;

        foreach ($buckets as $bucket) {
            $existing = MonthlyRanking::query()
                ->where('year', $year)->where('month', $month)->where('chain_bucket', $bucket)->first();

            if ($window->isFuture($now)) {
                $this->champions->computeAndStoreBucket($window, $bucket, finalize: true, force: $force, now: $now);
                $counts['future']++;

                continue;
            }

            if ($existing !== null && $existing->status === MonthlyRanking::STATUS_FINALIZED && ! $force) {
                $counts['skipped']++;
                $progress && $progress($bucket, 'skipped', $existing);

                continue;
            }

            $progress && $progress($bucket, 'researching', null);

            $candidates = $this->gather($window, $bucket, $providerFailures);
            $ranked = $this->rankTop3($this->validate($candidates, $window, $bucket));
            $rows = $this->persistTop3($window, $bucket, $ranked, count($candidates), $now);

            match ($rows->first()?->status) {
                MonthlyRanking::STATUS_FINALIZED => $counts['finalized']++,
                default => $counts['no_verified']++,
            };
            foreach ($rows as $row) {
                $touched[] = $this->summary($row);
            }
            $progress && $progress($bucket, 'done', $rows->first());
        }

        $result = new MonthlyChampionResearchRunResult(
            buckets: $touched,
            finalized: $counts['finalized'],
            bestSupportedCandidate: $counts['best_supported'],
            noVerifiedChampion: $counts['no_verified'],
            future: $counts['future'],
            skipped: $counts['skipped'],
            providerFailures: $providerFailures,
            providersUsed: array_map(fn (MonthlyChampionResearchProvider $p): string => $p->name(), $this->providers),
            durationSeconds: round(microtime(true) - $startedAt, 2),
        );

        Log::info('Monthly champion historical research completed', $result->toArray());

        return $result;
    }

    /**
     * @return list<MonthlyResearchCandidate>
     */
    private function gather(MonthWindow $window, string $bucket, int &$providerFailures): array
    {
        $known = Token::query()
            ->when(
                in_array($bucket, ChainBucket::CORE, true),
                fn ($q) => $q->where('chain_id', $bucket),
                fn ($q) => $q->whereNotIn('chain_id', ChainBucket::CORE),
            )
            ->whereHas('marketSnapshots', fn ($q) => $q->whereBetween('observed_at', [$window->start, $window->endExclusive]))
            ->get(['id', 'symbol', 'name', 'chain_id', 'token_address'])
            ->map(fn (Token $t): array => [
                'id' => (int) $t->id, 'symbol' => $t->symbol, 'name' => $t->name,
                'chain_id' => (string) $t->chain_id, 'token_address' => (string) $t->token_address,
            ])
            ->all();

        $context = new MonthlyResearchContext($window, $bucket, $known);

        $candidates = [];
        foreach ($this->providers as $provider) {
            if (! $provider->isAvailable()) {
                continue;
            }
            try {
                $found = $provider->research($context);
            } catch (Throwable $e) {
                $providerFailures++;
                Log::warning('Monthly research provider threw', ['provider' => $provider->name(), 'error' => $e->getMessage()]);

                continue;
            }
            if ($provider->lastCallFailed()) {
                $providerFailures++;
            }
            foreach ($found as $candidate) {
                if ($candidate instanceof MonthlyResearchCandidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  list<MonthlyResearchCandidate>  $candidates
     * @return list<MonthlyResearchCandidate>
     */
    private function validate(array $candidates, MonthWindow $window, string $bucket): array
    {
        $survivors = [];
        foreach ($candidates as $candidate) {
            // Entity identity + bucket.
            if (! $candidate->hasResolvedIdentity()) {
                $candidate->rejectReason = 'identity_unresolved';

                continue;
            }
            if (ChainBucket::forChain($candidate->chainId) !== $bucket) {
                $candidate->rejectReason = 'wrong_chain_bucket';

                continue;
            }

            // Market cap band — MARKET CAP, never FDV. A KNOWN peak outside the
            // band is a hard reject. A missing peak is allowed only for a
            // low-confidence best-supported candidate (handled at classify).
            if ($candidate->peakMarketCap !== null && ! $candidate->peakInBand(self::MIN_MC_USD, self::MAX_MC_USD)) {
                $candidate->rejectReason = 'peak_market_cap_out_of_band';

                continue;
            }

            // 30-day trading-age rule. If a launch date is known, its 30-day
            // window must overlap the month. If unknown -> age_uncertain (the
            // candidate is NOT dropped, but confidence is capped at classify).
            if (! $candidate->ageUncertain && $candidate->launchDate !== null) {
                $ageWindowEnd = $candidate->launchDate->addDays((int) config('dexscreener.filters.max_age_days', 30));
                if ($ageWindowEnd->lessThan($window->start) || $candidate->launchDate->greaterThanOrEqualTo($window->endExclusive)) {
                    $candidate->rejectReason = 'outside_first_30_days_of_trading_in_month';

                    continue;
                }
            }

            // Score (participation formula — holder + volume + market cap).
            if (! $candidate->isInternalObserved()) {
                $scored = $this->calculator->scoreHistorical(
                    $candidate->baselineMarketCap,
                    $candidate->peakMarketCap,
                    $candidate->volumeUsd,
                    $candidate->holderCount,
                );
                $candidate->performanceScore = $scored['performance_score'];
                $candidate->holderStrength = $scored['holder_strength'];
                $candidate->volumeStrength = $scored['volume_strength'];
                $candidate->marketCapStrength = $scored['market_cap_strength'];
                $candidate->marketCapGrowthPct = $scored['market_cap_growth_pct'];
                $candidate->peakExpansionRatio = $scored['peak_expansion_ratio'];
            }

            $survivors[] = $candidate;
        }

        return $survivors;
    }

    /**
     * The ranked Top `config('ranking.top_n')` (3), deduped by token identity.
     *
     * @param  list<MonthlyResearchCandidate>  $survivors
     * @return list<MonthlyResearchCandidate>
     */
    private function rankTop3(array $survivors): array
    {
        $n = max(1, (int) config('ranking.top_n', 3));

        usort($survivors, function (MonthlyResearchCandidate $a, MonthlyResearchCandidate $b): int {
            // 1. internal-observed evidence wins ties with external evidence.
            $ai = $a->isInternalObserved() ? 1 : 0;
            $bi = $b->isInternalObserved() ? 1 : 0;
            if ($ai !== $bi) {
                return $bi <=> $ai;
            }
            // 2. higher participation score (null last).
            $as = $a->performanceScore ?? -1.0;
            $bs = $b->performanceScore ?? -1.0;
            if ($as !== $bs) {
                return $bs <=> $as;
            }
            // 3. more / stronger sources.
            if ($a->hasStrongSource() !== $b->hasStrongSource()) {
                return ($b->hasStrongSource() ? 1 : 0) <=> ($a->hasStrongSource() ? 1 : 0);
            }
            if ($a->sourceCount() !== $b->sourceCount()) {
                return $b->sourceCount() <=> $a->sourceCount();
            }

            // 4. deterministic tie-break on the token key.
            return strcmp($a->tokenKey(), $b->tokenKey());
        });

        $picked = [];
        $seen = [];
        foreach ($survivors as $candidate) {
            $key = $candidate->tokenKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $picked[] = $candidate;
            if (count($picked) >= $n) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Write up to `config('ranking.top_n')` ranked rows for a researched bucket
     * (rank 1..N), delete any stale ranks, and record a single
     * `no_verified_result` row when nothing survives.
     *
     * @param  list<MonthlyResearchCandidate>  $ranked
     * @return Collection<int, MonthlyRanking>
     */
    private function persistTop3(MonthWindow $window, string $bucket, array $ranked, int $candidatesConsidered, CarbonImmutable $now): Collection
    {
        if ($ranked === []) {
            $this->deleteRanksFrom($window, $bucket, 2);
            /** @var MonthlyRanking $row */
            $row = MonthlyRanking::query()->firstOrNew([
                'year' => $window->year, 'month' => $window->month, 'chain_bucket' => $bucket, 'rank' => 1,
            ]);
            $row->fill([
                'token_id' => null,
                'champion_name' => null, 'champion_symbol' => null, 'champion_chain_id' => null,
                'champion_token_address' => null, 'champion_image_url' => null,
                'status' => MonthlyRanking::STATUS_NO_VERIFIED_RESULT,
                'performance_score' => null,
                'holder_count' => null, 'monthly_volume_usd' => null, 'month_market_cap' => null,
                'holder_strength' => null, 'volume_strength' => null, 'market_cap_strength' => null,
                'holder_checked_at' => null,
                'baseline_market_cap' => null, 'peak_market_cap' => null,
                'market_cap_growth_pct' => null, 'peak_expansion_ratio' => null, 'activity_score' => null,
                'observation_count' => null, 'observation_coverage_ratio' => null,
                'scoring_breakdown' => ['candidates_considered' => $candidatesConsidered, 'method' => 'historical_research'],
                'source_type' => null, 'source_reference' => null, 'source_evidence' => null,
                'age_uncertain' => false, 'confidence' => null,
                'computed_at' => $now, 'finalized_at' => $now,
            ]);
            $row->save();

            return collect([$row]);
        }

        $written = collect();
        foreach ($ranked as $index => $candidate) {
            $rank = $index + 1;
            [$status, $confidence] = $this->classify($candidate);

            /** @var MonthlyRanking $row */
            $row = MonthlyRanking::query()->firstOrNew([
                'year' => $window->year, 'month' => $window->month, 'chain_bucket' => $bucket, 'rank' => $rank,
            ]);
            $row->fill([
                'token_id' => $candidate->tokenId,
                'champion_name' => $candidate->tokenId === null ? $candidate->name : null,
                'champion_symbol' => $candidate->tokenId === null ? $candidate->symbol : null,
                'champion_chain_id' => $candidate->tokenId === null ? $candidate->chainId : null,
                'champion_token_address' => $candidate->tokenId === null ? $candidate->tokenAddress : null,
                'champion_image_url' => $candidate->tokenId === null ? $candidate->imageUrl : null,
                'status' => $status,
                'performance_score' => $candidate->performanceScore,
                'holder_count' => $candidate->holderCount,
                'monthly_volume_usd' => $candidate->volumeUsd,
                'month_market_cap' => $candidate->peakMarketCap,
                'holder_strength' => $candidate->holderStrength,
                'volume_strength' => $candidate->volumeStrength,
                'market_cap_strength' => $candidate->marketCapStrength,
                'holder_checked_at' => null,
                'baseline_market_cap' => $candidate->baselineMarketCap,
                'peak_market_cap' => $candidate->peakMarketCap,
                'market_cap_growth_pct' => $candidate->marketCapGrowthPct,
                'peak_expansion_ratio' => $candidate->peakExpansionRatio,
                'activity_score' => $candidate->activityScore,
                'observation_count' => $candidate->observationCount,
                'observation_coverage_ratio' => $candidate->observationCoverageRatio,
                'scoring_breakdown' => [
                    'method' => $candidate->isInternalObserved() ? 'internal_observed' : 'historical_research',
                    'candidates_considered' => $candidatesConsidered,
                    'explanation' => $candidate->explanation,
                    'source_type' => $candidate->sourceType,
                    'holder_strength' => $candidate->holderStrength,
                    'volume_strength' => $candidate->volumeStrength,
                    'market_cap_strength' => $candidate->marketCapStrength,
                ],
                'source_type' => $candidate->sourceType,
                'source_reference' => $this->reference($candidate),
                'source_evidence' => $candidate->isInternalObserved() ? null : $candidate->sourcesAsArray(),
                'age_uncertain' => $candidate->ageUncertain,
                'confidence' => $confidence,
                'computed_at' => $now,
                'finalized_at' => $now,
            ]);
            $row->save();
            $written->push($row);
        }

        $this->deleteRanksFrom($window, $bucket, $written->count() + 1);

        return $written;
    }

    private function deleteRanksFrom(MonthWindow $window, string $bucket, int $fromRank): void
    {
        MonthlyRanking::query()
            ->where('year', $window->year)->where('month', $window->month)
            ->where('chain_bucket', $bucket)->where('rank', '>=', $fromRank)
            ->delete();
    }

    /**
     * @return array{0:string,1:string} [status, confidence]
     */
    private function classify(MonthlyResearchCandidate $c): array
    {
        // Internal-observed path — matches the deterministic finalize command.
        if ($c->isInternalObserved()) {
            $coverage = $c->observationCoverageRatio ?? 0.0;
            $minCoverage = (float) config('ranking.min_observation_coverage', 0.25);
            if ($coverage >= $minCoverage && $c->performanceScore !== null) {
                return [
                    MonthlyRanking::STATUS_FINALIZED,
                    $coverage >= 0.5 ? MonthlyRanking::CONFIDENCE_HIGH : MonthlyRanking::CONFIDENCE_MEDIUM,
                ];
            }

            return [MonthlyRanking::STATUS_FINALIZED, MonthlyRanking::CONFIDENCE_LOW];
        }

        $peakInBand = $c->peakInBand(self::MIN_MC_USD, self::MAX_MC_USD);
        $strong = count(array_filter($c->sources, fn (MonthlyResearchSource $s): bool => $s->isStrong()));
        $primary = count(array_filter($c->sources, fn (MonthlyResearchSource $s): bool => $s->isPrimary()));
        $fullPerformance = $c->canComputeGrowth() && $c->performanceScore !== null;

        // The operator's suggested confidence is a CEILING — the service may
        // lower it, never raise it above what the evidence-provider claimed.
        $cap = fn (string $level): string => $this->minConfidence($level, $c->suggestedConfidence);

        // An exact DexScreener rank, directly established by a source.
        if ($c->sourceType === MonthlyRanking::SOURCE_EXACT_DEXSCREENER_RANK
            && $peakInBand && ! $c->ageUncertain && $strong >= 1) {
            return [MonthlyRanking::STATUS_FINALIZED, $cap($primary >= 1 && $strong >= 2 ? MonthlyRanking::CONFIDENCE_HIGH : MonthlyRanking::CONFIDENCE_MEDIUM)];
        }

        // A fully-supported best historical performer -> FINALIZED.
        if ($peakInBand && $fullPerformance && ! $c->ageUncertain && $strong >= 2) {
            return [
                MonthlyRanking::STATUS_FINALIZED,
                $cap($primary >= 1 && $strong >= 3 ? MonthlyRanking::CONFIDENCE_HIGH : MonthlyRanking::CONFIDENCE_MEDIUM),
            ];
        }

        // A real candidate clearly leads but the evidence is incomplete.
        if ($c->hasResolvedIdentity() && ($peakInBand || $c->peakMarketCap === null) && $c->sourceCount() >= 1) {
            $conf = ($peakInBand && $fullPerformance && $strong >= 1 && ! $c->ageUncertain)
                ? $cap(MonthlyRanking::CONFIDENCE_MEDIUM)
                : $cap(MonthlyRanking::CONFIDENCE_LOW);

            return [MonthlyRanking::STATUS_FINALIZED, $conf];
        }

        return [MonthlyRanking::STATUS_NO_VERIFIED_RESULT, MonthlyRanking::CONFIDENCE_LOW];
    }

    private function minConfidence(string $a, string $b): string
    {
        $rank = [MonthlyRanking::CONFIDENCE_LOW => 0, MonthlyRanking::CONFIDENCE_MEDIUM => 1, MonthlyRanking::CONFIDENCE_HIGH => 2];

        return ($rank[$a] ?? 0) <= ($rank[$b] ?? 0) ? $a : $b;
    }

    private function reference(MonthlyResearchCandidate $c): string
    {
        if ($c->isInternalObserved()) {
            return sprintf('internal snapshots: %d obs, coverage %.2f', (int) $c->observationCount, $c->observationCoverageRatio ?? 0.0);
        }

        $first = $c->sources[0] ?? null;
        $host = $first?->url !== null ? (parse_url($first->url, PHP_URL_HOST) ?: $first->name) : ($first?->name ?? 'research');

        return sprintf(
            '%s: %d source(s)%s%s',
            $host,
            $c->sourceCount(),
            $c->hasStrongSource() ? ' incl. strong' : '',
            $c->ageUncertain ? ' · age uncertain' : '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(MonthlyRanking $row): array
    {
        return [
            'bucket' => $row->chain_bucket,
            'status' => $row->status,
            'token_id' => $row->token_id,
            'champion' => $row->champion_symbol ?? $row->token?->symbol,
            'source_type' => $row->source_type,
            'confidence' => $row->confidence,
            'age_uncertain' => (bool) $row->age_uncertain,
        ];
    }
}
