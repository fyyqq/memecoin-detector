<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * Picks the ranked Top 3 from a bucket's candidates — deterministically
 * (Step 25).
 *
 * `selectTop3()` returns up to `config('ranking.top_n')` (3) candidates, ordered
 * by `performanceScore` desc. `eligible` candidates fill the ranks first; only
 * if fewer than N are eligible do `insufficient_observation` candidates fill the
 * remaining slots (a real token led the bucket but was observed thinly — the
 * row is still stored, at lower confidence). A token appears at most once.
 *
 * Tie-break (per the spec), in order: higher holder strength → higher volume
 * strength → higher market-cap strength → higher observation coverage → token
 * key ascending. Every tie-break is deterministic — no randomness. Risk score
 * and AI are NEVER used.
 */
class MonthlyChampionSelector
{
    /**
     * @param  list<MonthlyCandidate>  $candidates
     * @return list<MonthlyCandidate> 0..N, best first
     */
    public function selectTop3(array $candidates): array
    {
        $n = max(1, (int) config('ranking.top_n', 3));

        $eligible = $this->ordered(array_filter($candidates, fn (MonthlyCandidate $c): bool => $c->status === MonthlyCandidate::STATUS_ELIGIBLE && $c->performanceScore !== null));
        $thin = $this->ordered(array_filter($candidates, fn (MonthlyCandidate $c): bool => $c->status === MonthlyCandidate::STATUS_INSUFFICIENT_OBSERVATION && $c->performanceScore !== null));

        $picked = [];
        $seen = [];
        foreach ([...$eligible, ...$thin] as $candidate) {
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
     * The strongest single candidate (rank 1), or null. Convenience wrapper.
     *
     * @param  list<MonthlyCandidate>  $candidates
     */
    public function select(array $candidates): ?MonthlyCandidate
    {
        return $this->selectTop3($candidates)[0] ?? null;
    }

    /**
     * @param  iterable<MonthlyCandidate>  $candidates
     * @return list<MonthlyCandidate>
     */
    private function ordered(iterable $candidates): array
    {
        $list = is_array($candidates) ? array_values($candidates) : iterator_to_array($candidates, false);
        usort($list, fn (MonthlyCandidate $a, MonthlyCandidate $b): int => $this->compare($a, $b));

        return $list;
    }

    private function compare(MonthlyCandidate $a, MonthlyCandidate $b): int
    {
        $byScore = $b->performanceScore <=> $a->performanceScore;
        if ($byScore !== 0) {
            return $byScore;
        }

        return [
            $b->holderStrength ?? -1.0,
            $b->volumeStrength ?? -1.0,
            $b->marketCapStrength ?? -1.0,
            $b->observationCoverageRatio ?? -1.0,
        ] <=> [
            $a->holderStrength ?? -1.0,
            $a->volumeStrength ?? -1.0,
            $a->marketCapStrength ?? -1.0,
            $a->observationCoverageRatio ?? -1.0,
        ] ?: strcmp($a->tokenKey(), $b->tokenKey());
    }

    /**
     * The score of the first candidate NOT in the Top 3, for the audit trail.
     * Null when there were <= N eligible candidates.
     *
     * @param  list<MonthlyCandidate>  $candidates
     */
    public function runnerUpScore(array $candidates): ?float
    {
        $n = max(1, (int) config('ranking.top_n', 3));
        $eligible = $this->ordered(array_filter($candidates, fn (MonthlyCandidate $c): bool => $c->isEligible() && $c->performanceScore !== null));

        return isset($eligible[$n]) ? $eligible[$n]->performanceScore : null;
    }
}
