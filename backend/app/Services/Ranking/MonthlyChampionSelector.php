<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * Picks the single champion from a bucket's candidates — deterministically.
 *
 * `select()` returns the best `eligible` candidate. `selectAmong()` returns the
 * best candidate of ANY given status (used to record a `best_supported_candidate`
 * when only thinly-observed real tokens are available).
 *
 * The winner is the highest `performance_score`; ties break on, in order:
 * higher observed market-cap growth, higher peak market cap, higher observation
 * coverage, higher observation count, lower token id. Every tie-break is
 * deterministic — there is no randomness. Risk score and AI are NEVER used.
 */
class MonthlyChampionSelector
{
    /**
     * @param  list<MonthlyCandidate>  $candidates
     */
    public function select(array $candidates): ?MonthlyCandidate
    {
        return $this->selectAmong($candidates, MonthlyCandidate::STATUS_ELIGIBLE);
    }

    /**
     * @param  list<MonthlyCandidate>  $candidates
     * @param  MonthlyCandidate::STATUS_*  $status
     */
    public function selectAmong(array $candidates, string $status): ?MonthlyCandidate
    {
        $matching = array_values(array_filter(
            $candidates,
            fn (MonthlyCandidate $c): bool => $c->status === $status && $c->performanceScore !== null,
        ));

        if ($matching === []) {
            return null;
        }

        usort($matching, fn (MonthlyCandidate $a, MonthlyCandidate $b): int => $this->compare($a, $b));

        return $matching[0];
    }

    private function compare(MonthlyCandidate $a, MonthlyCandidate $b): int
    {
        return [
            $b->performanceScore,
            $b->marketCapGrowthPct ?? 0.0,
            $b->peakMarketCap ?? 0.0,
            $b->observationCoverageRatio ?? 0.0,
            $b->observationCount,
            -(int) $b->token->id,
        ] <=> [
            $a->performanceScore,
            $a->marketCapGrowthPct ?? 0.0,
            $a->peakMarketCap ?? 0.0,
            $a->observationCoverageRatio ?? 0.0,
            $a->observationCount,
            -(int) $a->token->id,
        ];
    }

    /**
     * The runner-up score among ELIGIBLE candidates, for the champion row's
     * audit trail. Null when there was fewer than two.
     *
     * @param  list<MonthlyCandidate>  $candidates
     */
    public function runnerUpScore(array $candidates): ?float
    {
        $scores = array_values(array_filter(array_map(
            fn (MonthlyCandidate $c): ?float => $c->isEligible() ? $c->performanceScore : null,
            $candidates,
        )));

        if (count($scores) < 2) {
            return null;
        }

        rsort($scores);

        return $scores[1];
    }
}
