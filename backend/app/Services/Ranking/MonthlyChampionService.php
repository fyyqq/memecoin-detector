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
 * Computes + persists "Monthly Top Memecoins" (Step 22, corrected).
 *
 * For a calendar month there is at most ONE champion per chain bucket
 * ({@see ChainBucket}: solana / robinhood / bsc / base / other). All ranking
 * logic lives here — never in a controller, never in the read API.
 *
 *   - the CURRENT month is `provisional` per bucket and recomputed daily;
 *   - a PAST bucket is settled once (`finalized`, `best_supported_candidate`, or
 *     `no_verified_champion`) and immutable during normal operation — only
 *     `--force` recomputes it;
 *   - a FUTURE month is `future` with `token_id = null` (synthesized by the API,
 *     never stored as a fabricated winner).
 *
 * Risk score and AI are NEVER used to select the winner.
 */
class MonthlyChampionService
{
    public function __construct(
        private readonly MonthlyPerformanceCalculator $calculator,
        private readonly MonthlyChampionSelector $selector,
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
        $bestSupported = 0;
        $noVerified = 0;
        $skippedSettled = 0;

        // Current month — every bucket provisional.
        $current = MonthWindow::containing($now);
        foreach (ChainBucket::ALL as $bucket) {
            $row = $this->computeAndStoreBucket($current, $bucket, finalize: false, force: false, now: $now);
            $months[] = $this->summary($row);
            match ($row->status) {
                MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION => $noVerified++,
                MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE => $bestSupported++,
                default => $provisional++,
            };
        }

        // Past months that still need settling.
        $window = $current->previous();
        for ($i = 0; $i < max(1, $backfillMonths); $i++) {
            foreach (ChainBucket::ALL as $bucket) {
                $existing = MonthlyRanking::query()
                    ->where('year', $window->year)
                    ->where('month', $window->month)
                    ->where('chain_bucket', $bucket)
                    ->first();

                if ($existing !== null && $existing->isSettled()) {
                    $skippedSettled++;

                    continue;
                }

                $stored = $this->computeAndStoreBucket($window, $bucket, finalize: true, force: false, now: $now);
                $months[] = $this->summary($stored);
                match ($stored->status) {
                    MonthlyRanking::STATUS_FINALIZED => $finalized++,
                    MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE => $bestSupported++,
                    default => $noVerified++,
                };
            }

            $window = $window->previous();
        }

        $result = new MonthlyChampionRunResult(
            months: $months,
            finalized: $finalized,
            provisional: $provisional,
            bestSupportedCandidate: $bestSupported,
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
     * @return Collection<int, MonthlyRanking> one row per bucket (or just the requested bucket)
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

        return collect($buckets)->map(
            fn (string $bucket): MonthlyRanking => $this->computeAndStoreBucket($window, $bucket, finalize: true, force: $force, now: $now),
        );
    }

    /**
     * Compute + upsert ONE bucket's ranking row for ONE month.
     */
    public function computeAndStoreBucket(MonthWindow $window, string $bucket, bool $finalize, bool $force, ?CarbonImmutable $now = null): MonthlyRanking
    {
        $now ??= CarbonImmutable::now();

        /** @var MonthlyRanking $ranking */
        $ranking = MonthlyRanking::query()->firstOrNew([
            'year' => $window->year,
            'month' => $window->month,
            'chain_bucket' => $bucket,
        ]);

        // A settled past bucket is immutable during normal operation.
        if ($ranking->exists && $ranking->isSettled() && ! $force) {
            return $ranking;
        }

        // A future month has no data — never a fabricated winner.
        if ($window->isFuture($now)) {
            $ranking->fill([
                'token_id' => null,
                'status' => MonthlyRanking::STATUS_FUTURE,
                'performance_score' => null,
                'baseline_market_cap' => null,
                'peak_market_cap' => null,
                'market_cap_growth_pct' => null,
                'peak_expansion_ratio' => null,
                'activity_score' => null,
                'observation_count' => null,
                'observation_coverage_ratio' => null,
                'scoring_breakdown' => null,
                'source_type' => null,
                'source_reference' => null,
                'confidence' => null,
                'computed_at' => $now,
                'finalized_at' => null,
            ]);

            // Only persist a future row if one already exists; otherwise let the
            // API synthesize it (keeps the table small).
            if ($ranking->exists) {
                $ranking->save();
            }

            return $ranking;
        }

        $candidates = $this->computeCandidates($window, $bucket, $now);
        [$champion, $championKind] = $this->pickChampion($candidates);
        $isPast = $window->isPast($now);
        $shouldFinalize = $finalize && $isPast;

        if ($champion === null) {
            $ranking->fill([
                'token_id' => null,
                'status' => MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION,
                'performance_score' => null,
                'baseline_market_cap' => null,
                'peak_market_cap' => null,
                'market_cap_growth_pct' => null,
                'peak_expansion_ratio' => null,
                'activity_score' => null,
                'observation_count' => null,
                'observation_coverage_ratio' => null,
                'scoring_breakdown' => [
                    'candidates_considered' => count($candidates),
                    'eligible_candidates' => 0,
                    'insufficient_observation_candidates' => $this->countByStatus($candidates, MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION),
                ],
                'source_type' => null,
                'source_reference' => null,
                'confidence' => null,
                'computed_at' => $now,
                'finalized_at' => $shouldFinalize ? $now : null,
            ]);
            $ranking->save();

            return $ranking;
        }

        $coverage = $champion->observationCoverageRatio ?? 0.0;

        if ($championKind === 'eligible') {
            $status = $shouldFinalize
                ? MonthlyRanking::STATUS_FINALIZED
                : MonthlyRanking::STATUS_PROVISIONAL;
            $confidence = $coverage >= 0.5 ? MonthlyRanking::CONFIDENCE_HIGH : MonthlyRanking::CONFIDENCE_MEDIUM;
            $sourceRef = sprintf('internal snapshots: %d obs, coverage %.2f', $champion->observationCount, $coverage);
        } else {
            // Only thinly-observed candidates — a real token led the bucket but
            // the evidence is incomplete.
            $status = $shouldFinalize
                ? MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE
                : MonthlyRanking::STATUS_PROVISIONAL;
            $confidence = MonthlyRanking::CONFIDENCE_LOW;
            $sourceRef = sprintf('internal snapshots (thin coverage): %d obs, coverage %.2f', $champion->observationCount, $coverage);
        }

        $ranking->fill([
            'token_id' => $champion->token->id,
            'status' => $status,
            'performance_score' => $champion->performanceScore,
            'baseline_market_cap' => $champion->baselineMarketCap,
            'peak_market_cap' => $champion->peakMarketCap,
            'market_cap_growth_pct' => $champion->marketCapGrowthPct,
            'peak_expansion_ratio' => $champion->peakExpansionRatio,
            'activity_score' => $champion->activityScore,
            'observation_count' => $champion->observationCount,
            'observation_coverage_ratio' => $champion->observationCoverageRatio,
            'scoring_breakdown' => [
                ...$champion->breakdown,
                'candidates_considered' => count($candidates),
                'eligible_candidates' => $this->countByStatus($candidates, MonthlyCandidate::STATUS_ELIGIBLE),
                'insufficient_observation_candidates' => $this->countByStatus($candidates, MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION),
                'runner_up_score' => $this->selector->runnerUpScore($candidates),
                'champion_kind' => $championKind,
            ],
            'source_type' => MonthlyRanking::SOURCE_INTERNAL_OBSERVED,
            'source_reference' => $sourceRef,
            'confidence' => $confidence,
            'computed_at' => $now,
            'finalized_at' => in_array($status, [
                MonthlyRanking::STATUS_FINALIZED,
                MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE,
            ], true) ? $now : null,
        ]);
        $ranking->save();

        return $ranking;
    }

    /**
     * @param  list<MonthlyCandidate>  $candidates
     * @return array{0: MonthlyCandidate|null, 1: 'eligible'|'insufficient'|null}
     */
    private function pickChampion(array $candidates): array
    {
        $eligibleWinner = $this->selector->select($candidates);
        if ($eligibleWinner !== null) {
            return [$eligibleWinner, 'eligible'];
        }

        $thin = $this->selector->selectAmong(
            $candidates,
            MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION,
        );

        return $thin !== null ? [$thin, 'insufficient'] : [null, null];
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
            // The token must belong to THIS chain bucket.
            ->when(
                in_array($bucket, ChainBucket::CORE, true),
                fn (Builder $q) => $q->where('chain_id', $bucket),
                fn (Builder $q) => $q->whereNotIn('chain_id', ChainBucket::CORE),
            )
            // Verified / observed market-cap floor.
            ->where(function (Builder $q) use ($min): void {
                $q->where('observed_peak_market_cap', '>=', $min)
                    ->orWhere(function (Builder $q2) use ($min): void {
                        $q2->where('historical_peak_status', HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED)
                            ->where('historical_peak_value', '>=', $min);
                    });
            })
            // Ceiling — never a token that ever printed a verified/observed peak > $200M.
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

        // One query for every candidate token's snapshots in the month.
        $snapshotsByToken = MarketSnapshot::query()
            ->whereIn('token_id', $tokens->pluck('id'))
            ->whereBetween('observed_at', [$window->start, $endInclusive])
            ->get()
            ->groupBy('token_id');

        $candidates = [];
        foreach ($tokens as $token) {
            $candidates[] = $this->calculator->evaluate(
                $token,
                $snapshotsByToken->get($token->id, collect()),
                $window,
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
            'status' => $ranking->status,
            'token_id' => $ranking->token_id,
            'performance_score' => $ranking->performance_score,
        ];
    }
}
