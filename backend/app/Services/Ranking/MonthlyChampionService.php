<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\MonthlyRanking;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Computes + persists "Monthly Top Memecoins" (Step 25, Top 3).
 *
 * For a calendar month + chain bucket ({@see ChainBucket}: solana / robinhood /
 * bsc / base / other) there are up to THREE ranked rows, unique on
 * `(year, month, chain_bucket, rank)`. All ranking logic lives here — never in a
 * controller, never in the read API.
 *
 *   - the CURRENT month's 5 buckets are `provisional` and recomputed daily;
 *   - a PAST bucket is settled once (`finalized` or `no_verified_result`) and
 *     immutable during normal operation — only `--force` recomputes it;
 *   - a FUTURE month is `future` with `entries: []` (synthesized by the API,
 *     never stored as a fabricated position).
 *
 * The score rewards real participation (holder count 0.40 + representative
 * volume 0.35 + month-peak market cap 0.25, log-normalized, renormalized over
 * the components that are known). Risk score, AI and social sentiment are NEVER
 * used. Market cap is supporting evidence — it cannot dominate the selection.
 */
class MonthlyChampionService
{
    /**
     * Holder observations from the most recent `computeCandidates()` call, keyed
     * `"$year-$month-$bucket"` → `array<int tokenId, MonthlyHolderObservation>`.
     * Bridges the candidate pass (which polls GeckoTerminal) to the persist loop
     * (which records `holder_checked_at`).
     *
     * @var array<string, array<int, MonthlyHolderObservation>>
     */
    private array $pendingHolderObs = [];

    public function __construct(
        private readonly MonthlyPerformanceCalculator $calculator,
        private readonly MonthlyChampionSelector $selector,
        private readonly MonthlyHolderCollector $holders,
    ) {}

    /**
     * The daily scheduled pass: refresh every bucket of the current provisional
     * month and settle every not-yet-settled bucket of any past month back to
     * `$backfillMonths`.
     */
    public function refresh(?CarbonImmutable $now = null, int $backfillMonths = 3): MonthlyChampionRunResult
    {
        $now ??= CarbonImmutable::now();
        $startedAt = microtime(true);

        $months = [];
        $finalized = 0;
        $provisional = 0;
        $noVerified = 0;
        $skippedSettled = 0;

        $current = MonthWindow::containing($now);
        foreach (ChainBucket::ALL as $bucket) {
            $rows = $this->computeAndStoreBucket($current, $bucket, finalize: false, force: false, now: $now);
            foreach ($rows as $row) {
                $months[] = $this->summary($row);
            }
            $status = $rows->first()?->status ?? MonthlyRanking::STATUS_PROVISIONAL;
            $status === MonthlyRanking::STATUS_NO_VERIFIED_RESULT ? $noVerified++ : $provisional++;
        }

        $window = $current->previous();
        for ($i = 0; $i < max(1, $backfillMonths); $i++) {
            foreach (ChainBucket::ALL as $bucket) {
                $existing = MonthlyRanking::query()
                    ->where('year', $window->year)->where('month', $window->month)
                    ->where('chain_bucket', $bucket)->orderBy('rank')->first();

                if ($existing !== null && $existing->isSettled()) {
                    $skippedSettled++;

                    continue;
                }

                $rows = $this->computeAndStoreBucket($window, $bucket, finalize: true, force: false, now: $now);
                foreach ($rows as $row) {
                    $months[] = $this->summary($row);
                }
                ($rows->first()?->status === MonthlyRanking::STATUS_FINALIZED) ? $finalized++ : $noVerified++;
            }

            $window = $window->previous();
        }

        $result = new MonthlyChampionRunResult(
            months: $months,
            finalized: $finalized,
            provisional: $provisional,
            bestSupportedCandidate: 0,
            noVerifiedChampion: $noVerified,
            skippedSettled: $skippedSettled,
            durationSeconds: round(microtime(true) - $startedAt, 2),
        );

        Log::info('Monthly champion refresh completed', $result->toArray());

        return $result;
    }

    /**
     * Explicit finalization of one month's buckets. Refuses to finalize a month
     * that is not yet complete unless `$force` is set.
     *
     * @return Collection<int, MonthlyRanking> flat list of every ranked row written
     */
    public function finalizeMonth(int $year, int $month, bool $force, ?CarbonImmutable $now = null, ?string $onlyBucket = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $window = MonthWindow::of($year, $month);

        if (! $window->isPast($now) && ! $force) {
            throw new \InvalidArgumentException(
                sprintf('%s %d is not a completed calendar month yet — use --force to finalize it anyway.', $window->monthName(), $year),
            );
        }

        $buckets = $onlyBucket !== null ? [$onlyBucket] : ChainBucket::ALL;

        return collect($buckets)
            ->flatMap(fn (string $bucket): Collection => $this->computeAndStoreBucket($window, $bucket, finalize: true, force: $force, now: $now))
            ->values();
    }

    /**
     * Compute + upsert ONE bucket's ranked rows (up to `ranking.top_n`) for ONE
     * month. Returns the rows written (rank order); an empty/no-verified bucket
     * returns a single rank-1 row.
     *
     * @return Collection<int, MonthlyRanking>
     */
    public function computeAndStoreBucket(MonthWindow $window, string $bucket, bool $finalize, bool $force, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        $existingRank1 = MonthlyRanking::query()
            ->where('year', $window->year)->where('month', $window->month)
            ->where('chain_bucket', $bucket)->orderBy('rank')->first();

        // A settled past bucket is immutable during normal operation.
        if ($existingRank1 !== null && $existingRank1->isSettled() && ! $force) {
            return MonthlyRanking::query()
                ->where('year', $window->year)->where('month', $window->month)
                ->where('chain_bucket', $bucket)->orderBy('rank')->get();
        }

        // A future month has no data — never a fabricated position. Let the API
        // synthesize it (keeps the table small); clear any stray stored rows.
        if ($window->isFuture($now)) {
            $this->deleteRanksFrom($window, $bucket, 1);

            return collect();
        }

        $isPast = $window->isPast($now);
        $shouldFinalize = $finalize && $isPast;

        $candidates = $this->computeCandidates($window, $bucket, $now);
        $top = $this->selector->selectTop3($candidates);

        // Nothing defensible.
        if ($top === []) {
            $this->deleteRanksFrom($window, $bucket, 2);

            if (! $isPast) {
                // current month, no candidates yet — synthesize as provisional.
                $this->deleteRanksFrom($window, $bucket, 1);

                return collect();
            }

            $row = MonthlyRanking::query()->firstOrNew([
                'year' => $window->year, 'month' => $window->month, 'chain_bucket' => $bucket, 'rank' => 1,
            ]);
            $row->fill([
                'token_id' => null, 'champion_name' => null, 'champion_symbol' => null,
                'champion_chain_id' => null, 'champion_token_address' => null, 'champion_image_url' => null,
                'status' => MonthlyRanking::STATUS_NO_VERIFIED_RESULT,
                'performance_score' => null,
                'holder_count' => null, 'monthly_volume_usd' => null, 'month_market_cap' => null,
                'holder_strength' => null, 'volume_strength' => null, 'market_cap_strength' => null,
                'holder_checked_at' => null,
                'baseline_market_cap' => null, 'peak_market_cap' => null, 'market_cap_growth_pct' => null,
                'peak_expansion_ratio' => null, 'activity_score' => null,
                'observation_count' => null, 'observation_coverage_ratio' => null,
                'scoring_breakdown' => [
                    'method' => 'internal_observed',
                    'candidates_considered' => count($candidates),
                    'eligible_candidates' => $this->countByStatus($candidates, MonthlyCandidate::STATUS_ELIGIBLE),
                    'insufficient_observation_candidates' => $this->countByStatus($candidates, MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION),
                ],
                'source_type' => null, 'source_reference' => null, 'source_evidence' => null,
                'age_uncertain' => false, 'confidence' => null,
                'computed_at' => $now, 'finalized_at' => $now,
            ]);
            $row->save();

            return collect([$row]);
        }

        $status = $shouldFinalize ? MonthlyRanking::STATUS_FINALIZED : MonthlyRanking::STATUS_PROVISIONAL;
        $holderObs = $this->lastHolderObservations($window, $bucket);
        $eligibleCount = $this->countByStatus($candidates, MonthlyCandidate::STATUS_ELIGIBLE);
        $insufficientCount = $this->countByStatus($candidates, MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION);
        $runnerUp = $this->selector->runnerUpScore($candidates);

        $written = collect();
        foreach ($top as $index => $candidate) {
            $rank = $index + 1;
            $coverage = $candidate->observationCoverageRatio ?? 0.0;
            $confidence = match (true) {
                $candidate->status === MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION => MonthlyRanking::CONFIDENCE_LOW,
                $coverage >= 0.5 => MonthlyRanking::CONFIDENCE_HIGH,
                default => MonthlyRanking::CONFIDENCE_MEDIUM,
            };
            $obs = $holderObs[$candidate->token->id] ?? null;

            $row = MonthlyRanking::query()->firstOrNew([
                'year' => $window->year, 'month' => $window->month, 'chain_bucket' => $bucket, 'rank' => $rank,
            ]);
            $row->fill([
                'token_id' => $candidate->token->id,
                'champion_name' => null, 'champion_symbol' => null, 'champion_chain_id' => null,
                'champion_token_address' => null, 'champion_image_url' => null,
                'status' => $status,
                'performance_score' => $candidate->performanceScore,
                'holder_count' => $candidate->holderCount,
                'monthly_volume_usd' => $candidate->monthlyVolumeUsd,
                'month_market_cap' => $candidate->monthMarketCap,
                'holder_strength' => $candidate->holderStrength,
                'volume_strength' => $candidate->volumeStrength,
                'market_cap_strength' => $candidate->marketCapStrength,
                'holder_checked_at' => $obs?->checkedAt,
                'baseline_market_cap' => $candidate->baselineMarketCap,
                'peak_market_cap' => $candidate->peakMarketCap,
                'market_cap_growth_pct' => $candidate->marketCapGrowthPct,
                'peak_expansion_ratio' => $candidate->peakExpansionRatio,
                'activity_score' => $candidate->activityScore,
                'observation_count' => $candidate->observationCount,
                'observation_coverage_ratio' => $candidate->observationCoverageRatio,
                'scoring_breakdown' => [
                    ...$candidate->breakdown,
                    'candidates_considered' => count($candidates),
                    'eligible_candidates' => $eligibleCount,
                    'insufficient_observation_candidates' => $insufficientCount,
                    'runner_up_score' => $runnerUp,
                    'candidate_status' => $candidate->status,
                ],
                'source_type' => MonthlyRanking::SOURCE_INTERNAL_OBSERVED,
                'source_reference' => sprintf('internal snapshots: %d obs, coverage %.2f', $candidate->observationCount, $coverage),
                'source_evidence' => null,
                'age_uncertain' => false,
                'confidence' => $confidence,
                'computed_at' => $now,
                'finalized_at' => $status === MonthlyRanking::STATUS_FINALIZED ? $now : null,
            ]);
            $row->save();
            $written->push($row);
        }

        // Drop any stale ranks beyond what we just wrote.
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
     * @return array<int, MonthlyHolderObservation> keyed by token id
     */
    private function lastHolderObservations(MonthWindow $window, string $bucket): array
    {
        return $this->pendingHolderObs[$window->year.'-'.$window->month.'-'.$bucket] ?? [];
    }

    /**
     * @param  list<MonthlyCandidate>  $candidates
     */
    private function countByStatus(array $candidates, string $status): int
    {
        return count(array_filter($candidates, fn (MonthlyCandidate $c): bool => $c->status === $status));
    }

    /**
     * @return list<MonthlyCandidate>
     */
    public function computeCandidates(MonthWindow $window, string $bucket, CarbonImmutable $now): array
    {
        $min = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $max = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');
        $endInclusive = $window->endExclusive->subMicrosecond();

        /** @var Collection<int, Token> $tokens */
        $tokens = Token::query()
            ->whereNotNull('earliest_pair_created_at')
            ->where('earliest_pair_created_at', '<', $window->endExclusive)
            ->when(
                in_array($bucket, ChainBucket::CORE, true),
                fn (Builder $q) => $q->where('chain_id', $bucket),
                fn (Builder $q) => $q->whereNotIn('chain_id', ChainBucket::CORE),
            )
            ->where(function (Builder $q) use ($min): void {
                $q->where('observed_peak_market_cap', '>=', $min)
                    ->orWhere(function (Builder $q2) use ($min): void {
                        $q2->where('historical_peak_status', HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED)
                            ->where('historical_peak_value', '>=', $min);
                    });
            })
            ->whereRaw(
                'GREATEST(COALESCE(observed_peak_market_cap, 0), COALESCE(historical_peak_value, 0)) <= ?',
                [$max],
            )
            ->whereHas('marketSnapshots', fn (Builder $q) => $q->whereBetween('observed_at', [$window->start, $endInclusive]))
            ->with('historicalPeakEvidence')
            ->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        /** @var Collection<int, Collection<int, MarketSnapshot>> $snapshotsByToken */
        $snapshotsByToken = MarketSnapshot::query()
            ->whereIn('token_id', $tokens->pluck('id'))
            ->whereBetween('observed_at', [$window->start, $endInclusive])
            ->get()
            ->groupBy('token_id');

        // Holder pass — only for the CURRENT provisional month (no live
        // GeckoTerminal history exists for a completed month).
        $holderObs = $window->isCurrent($now)
            ? $this->holders->collect($window->year, $window->month, $tokens, $now)
            : [];
        $this->pendingHolderObs[$window->year.'-'.$window->month.'-'.$bucket] = $holderObs;

        $candidates = [];
        foreach ($tokens as $token) {
            $candidates[] = $this->calculator->evaluate(
                $token,
                $snapshotsByToken->get($token->id, collect()),
                $window,
                $holderObs[$token->id]->holderCount ?? null,
                $now,
            );
        }

        return $candidates;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(MonthlyRanking $ranking): array
    {
        return [
            'year' => $ranking->year,
            'month' => $ranking->month,
            'chain_bucket' => $ranking->chain_bucket,
            'rank' => $ranking->rank,
            'status' => $ranking->status,
            'token_id' => $ranking->token_id,
            'performance_score' => $ranking->performance_score,
        ];
    }
}
