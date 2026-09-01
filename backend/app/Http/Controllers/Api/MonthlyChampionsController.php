<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlyRanking;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/monthly-champions?year=YYYY
 *
 * "Monthly Top Memecoins" (Step 25, Top 3). Read-only. **Only reads
 * `monthly_rankings`** (+ the ranked `Token`) — it never recomputes a ranking,
 * never queries `market_snapshots`, never calls DexScreener / CoinGecko /
 * GeckoTerminal, and NEVER performs web research.
 *
 * ALWAYS returns exactly 12 month entries (January … December). EACH month
 * ALWAYS contains exactly the FIVE chain buckets — `solana`, `robinhood`,
 * `bsc`, `base`, `other`. Each bucket carries a `status` and 0–3 ranked
 * `entries`:
 *
 *   - `provisional` — the current month; entries can change as data arrives;
 *   - `finalized`   — a completed month with defensible ranked entries (entries
 *                     may carry `confidence: low` where the evidence is thin);
 *   - `no_verified_result` — a completed month with no defensible candidate,
 *                            `entries: []` (never a fabricated position);
 *   - `future`      — a month that has not happened yet, `entries: []`.
 */
class MonthlyChampionsController extends Controller
{
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();

        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2020', 'max:'.((int) $now->year + 1)],
        ]);
        $year = (int) ($validated['year'] ?? $now->year);

        /** @var Collection<int, Collection<string, Collection<int, MonthlyRanking>>> $byMonth */
        $byMonth = MonthlyRanking::query()
            ->where('year', $year)
            ->with(['token:id,symbol,name,chain_id,token_address,image_url'])
            ->orderBy('rank')
            ->get()
            ->groupBy('month')
            ->map(fn (Collection $rows): Collection => $rows->groupBy('chain_bucket'));

        $data = [];
        for ($month = 1; $month <= 12; $month++) {
            $window = MonthWindow::of($year, $month);
            $stored = $byMonth->get($month, collect());
            $monthStatus = $this->monthStatus($window, $now);

            $champions = [];
            foreach (ChainBucket::ALL as $bucket) {
                /** @var Collection<int, MonthlyRanking> $rows */
                $rows = $stored->get($bucket, collect());
                $champions[$bucket] = $this->bucketPayload($bucket, $rows, $monthStatus);
            }

            $data[] = [
                'year' => $year,
                'month' => $month,
                'month_name' => self::MONTH_NAMES[$month],
                'status' => $monthStatus,
                'champions' => $champions,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'year' => $year,
                'count' => count($data),
                'current_year' => (int) $now->year,
                'current_month' => (int) $now->month,
                'buckets' => ChainBucket::ALL,
                'top_n' => (int) config('ranking.top_n', 3),
                'weights' => config('ranking.weights'),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'monthly_rankings',
                'selection_note' => 'Top 3 memecoins per chain bucket per month by real participation — holder count (0.40), representative monthly volume (0.35) and month-peak observed/verified market cap (0.25), log-normalized. Market cap is supporting evidence and cannot dominate. Not a prediction of future returns, not an investment recommendation.',
            ],
        ]);
    }

    private function monthStatus(MonthWindow $window, CarbonImmutable $now): string
    {
        return match (true) {
            $window->isFuture($now) => MonthlyRanking::STATUS_FUTURE,
            $window->isCurrent($now) => MonthlyRanking::STATUS_PROVISIONAL,
            default => MonthlyRanking::STATUS_FINALIZED,
        };
    }

    /**
     * @param  Collection<int, MonthlyRanking>  $rows
     * @return array{chain_bucket:string,status:string,entries:list<array<string,mixed>>}
     */
    private function bucketPayload(string $bucket, Collection $rows, string $monthStatus): array
    {
        $real = $rows
            ->filter(fn (MonthlyRanking $r): bool => in_array($r->status, MonthlyRanking::STATUSES_WITH_TOKEN, true))
            ->sortBy('rank')
            ->values();

        if ($real->isEmpty()) {
            // A stored no_verified_result row settles a past bucket; otherwise
            // synthesize (provisional / future — never a fabricated entry).
            $storedStatus = $rows->first()?->status;
            $status = $storedStatus === MonthlyRanking::STATUS_NO_VERIFIED_RESULT
                ? MonthlyRanking::STATUS_NO_VERIFIED_RESULT
                : ($monthStatus === MonthlyRanking::STATUS_FINALIZED
                    ? MonthlyRanking::STATUS_NO_VERIFIED_RESULT
                    : $monthStatus);

            return ['chain_bucket' => $bucket, 'status' => $status, 'entries' => []];
        }

        return [
            'chain_bucket' => $bucket,
            'status' => $real->first()->status,
            'entries' => $real->map(fn (MonthlyRanking $r): array => $this->entry($r))->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(MonthlyRanking $r): array
    {
        return [
            'rank' => (int) $r->rank,
            'token' => $r->championIdentity(),
            'performance' => [
                'score' => $r->performance_score,
                'holder_count' => $r->holder_count,               // null => UNKNOWN
                'monthly_volume' => $r->monthly_volume_usd,
                'market_cap' => $r->month_market_cap,             // month-peak observed/verified MC
                'holder_strength' => $r->holder_strength,
                'volume_strength' => $r->volume_strength,
                'market_cap_strength' => $r->market_cap_strength,
                // info-only context
                'market_cap_growth_pct' => $r->market_cap_growth_pct,
                'peak_expansion_ratio' => $r->peak_expansion_ratio,
                'observation_coverage_ratio' => $r->observation_coverage_ratio,
            ],
            'source_type' => $r->source_type,
            'source_reference' => $r->source_reference,
            'source_evidence' => $r->source_evidence ?? [],
            'age_uncertain' => (bool) $r->age_uncertain,
            'confidence' => $r->confidence,
            'finalized_at' => $r->finalized_at?->toIso8601String(),
            'computed_at' => $r->computed_at?->toIso8601String(),
        ];
    }
}
