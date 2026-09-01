<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Ranking\Providers\InternalObservedMonthlyResearchProvider;
use App\Services\Ranking\Providers\SeedFileMonthlyResearchProvider;
use App\Services\Ranking\Providers\WebMonthlyResearchProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Historical Monthly Champion Backfill (Step 25).
 *
 * For a PAST completed month + chain bucket, identify the best-supported #1
 * performing memecoin from research evidence — we do NOT just return
 * "no champion" because our MarketSnapshot history started in late August 2026.
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
 *   4. rank survivors with the deterministic performance formula
 *      ({@see MonthlyPerformanceCalculator::scoreHistorical});
 *   5. classify:
 *        FINALIZED                — sufficient evidence (internal-observed
 *                                   eligible winner, an exact DexScreener rank
 *                                   from a source, or a fully-supported
 *                                   historical performer);
 *        BEST_SUPPORTED_CANDIDATE — a real candidate clearly leads but the
 *                                   evidence is incomplete;
 *        NO_VERIFIED_CHAMPION     — no defensible candidate.
 *
 * NEVER fabricates a winner, a date, a source or a DexScreener rank. NEVER uses
 * the current Risk Assessment. NEVER uses AI. Invoked ONLY by
 * `memecoins:research-monthly-champions` — never the read API, never the daily
 * finalize pass.
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
                $row = $this->champions->computeAndStoreBucket($window, $bucket, finalize: true, force: $force, now: $now);
                $counts['future']++;
                $touched[] = $this->summary($row);

                continue;
            }

            if ($existing !== null && $existing->status === MonthlyRanking::STATUS_FINALIZED && ! $force) {
                $counts['skipped']++;
                $progress && $progress($bucket, 'skipped', $existing);

                continue;
            }

            $progress && $progress($bucket, 'researching', null);

            $candidates = $this->gather($window, $bucket, $providerFailures);
            $winner = $this->rank($this->validate($candidates, $window, $bucket));
            $row = $this->persist($window, $bucket, $winner, count($candidates), $now);

            match ($row->status) {
                MonthlyRanking::STATUS_FINALIZED => $counts['finalized']++,
                MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE => $counts['best_supported']++,
                default => $counts['no_verified']++,
            };
            $touched[] = $this->summary($row);
            $progress && $progress($bucket, 'done', $row);
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

            // Score.
            if (! $candidate->isInternalObserved()) {
                $scored = $this->calculator->scoreHistorical(
                    $candidate->baselineMarketCap,
                    $candidate->peakMarketCap,
                    $candidate->volumeUsd,
                );
                $candidate->performanceScore = $scored['performance_score'];
                $candidate->marketCapGrowthPct = $scored['market_cap_growth_pct'];
                $candidate->peakExpansionRatio = $scored['peak_expansion_ratio'];
                $candidate->activityScore = $scored['activity_score'];
            }

            $survivors[] = $candidate;
        }

        return $survivors;
    }

    /**
     * @param  list<MonthlyResearchCandidate>  $survivors
     */
    private function rank(array $survivors): ?MonthlyResearchCandidate
    {
        if ($survivors === []) {
            return null;
        }

        usort($survivors, function (MonthlyResearchCandidate $a, MonthlyResearchCandidate $b): int {
            // 1. internal-observed evidence wins ties with external evidence.
            $ai = $a->isInternalObserved() ? 1 : 0;
            $bi = $b->isInternalObserved() ? 1 : 0;
            if ($ai !== $bi) {
                return $bi <=> $ai;
            }
            // 2. higher performance score (null last).
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

            // 4. deterministic tie-break on symbol.
            return strcmp(mb_strtolower($a->symbol), mb_strtolower($b->symbol));
        });

        return $survivors[0];
    }

    private function persist(MonthWindow $window, string $bucket, ?MonthlyResearchCandidate $winner, int $candidatesConsidered, CarbonImmutable $now): MonthlyRanking
    {
        /** @var MonthlyRanking $row */
        $row = MonthlyRanking::query()->firstOrNew([
            'year' => $window->year, 'month' => $window->month, 'chain_bucket' => $bucket,
        ]);

        if ($winner === null) {
            $row->fill([
                'token_id' => null,
                'champion_name' => null, 'champion_symbol' => null, 'champion_chain_id' => null,
                'champion_token_address' => null, 'champion_image_url' => null,
                'status' => MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION,
                'performance_score' => null, 'baseline_market_cap' => null, 'peak_market_cap' => null,
                'market_cap_growth_pct' => null, 'peak_expansion_ratio' => null, 'activity_score' => null,
                'observation_count' => null, 'observation_coverage_ratio' => null,
                'scoring_breakdown' => ['candidates_considered' => $candidatesConsidered, 'method' => 'historical_research'],
                'source_type' => null, 'source_reference' => null, 'source_evidence' => null,
                'age_uncertain' => false, 'confidence' => null,
                'computed_at' => $now, 'finalized_at' => $now,
            ]);
            $row->save();

            return $row;
        }

        [$status, $confidence] = $this->classify($winner);

        $row->fill([
            'token_id' => $winner->tokenId,
            'champion_name' => $winner->tokenId === null ? $winner->name : null,
            'champion_symbol' => $winner->tokenId === null ? $winner->symbol : null,
            'champion_chain_id' => $winner->tokenId === null ? $winner->chainId : null,
            'champion_token_address' => $winner->tokenId === null ? $winner->tokenAddress : null,
            'champion_image_url' => $winner->tokenId === null ? $winner->imageUrl : null,
            'status' => $status,
            'performance_score' => $winner->performanceScore,
            'baseline_market_cap' => $winner->baselineMarketCap,
            'peak_market_cap' => $winner->peakMarketCap,
            'market_cap_growth_pct' => $winner->marketCapGrowthPct,
            'peak_expansion_ratio' => $winner->peakExpansionRatio,
            'activity_score' => $winner->activityScore,
            'observation_count' => $winner->observationCount,
            'observation_coverage_ratio' => $winner->observationCoverageRatio,
            'scoring_breakdown' => [
                'method' => $winner->isInternalObserved() ? 'internal_observed' : 'historical_research',
                'candidates_considered' => $candidatesConsidered,
                'explanation' => $winner->explanation,
                'source_type' => $winner->sourceType,
            ],
            'source_type' => $winner->sourceType,
            'source_reference' => $this->reference($winner),
            'source_evidence' => $winner->isInternalObserved() ? null : $winner->sourcesAsArray(),
            'age_uncertain' => $winner->ageUncertain,
            'confidence' => $confidence,
            'computed_at' => $now,
            'finalized_at' => $now,
        ]);
        $row->save();

        return $row;
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

            return [MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, MonthlyRanking::CONFIDENCE_LOW];
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

            return [MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, $conf];
        }

        return [MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, MonthlyRanking::CONFIDENCE_LOW];
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
