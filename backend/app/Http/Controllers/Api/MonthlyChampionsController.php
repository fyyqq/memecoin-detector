<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/monthly-champions?year=YYYY
 *
 * Read-only. **Only reads `monthly_rankings`** (+ the champion `Token`) — it
 * never recomputes a ranking, never queries `market_snapshots`, never calls
 * DexScreener / CoinGecko / GeckoTerminal, and NEVER performs web research.
 *
 * ALWAYS returns exactly 12 month entries (January … December). EACH month
 * ALWAYS contains exactly the FIVE chain buckets — `solana`, `robinhood`,
 * `bsc`, `base`, `other` — even when empty. Buckets are never omitted.
 *
 *   - a stored bucket row is returned as-is (`finalized` /
 *     `best_supported_candidate` / `no_verified_champion` / `provisional` /
 *     `future`);
 *   - a bucket with no stored row is synthesized: `provisional` for the current
 *     month, `future` for a future month, `no_verified_champion` for a past
 *     month that was never computed. Never a fabricated winner.
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

        /** @var Collection<int, Collection<string, MonthlyRanking>> $byMonth */
        $byMonth = MonthlyRanking::query()
            ->where('year', $year)
            ->with(['token:id,symbol,name,chain_id,token_address,image_url'])
            ->get()
            ->groupBy('month')
            ->map(fn (Collection $rows): Collection => $rows->keyBy('chain_bucket'));

        $data = [];
        for ($month = 1; $month <= 12; $month++) {
            $window = MonthWindow::of($year, $month);
            $stored = $byMonth->get($month, collect());

            $champions = [];
            foreach (ChainBucket::ALL as $bucket) {
                /** @var MonthlyRanking|null $row */
                $row = $stored->get($bucket);
                $champions[$bucket] = $row !== null
                    ? $this->fromRow($row)
                    : $this->synthesizeBucket($bucket, $window, $now);
            }

            $data[] = [
                'year' => $year,
                'month' => $month,
                'month_name' => self::MONTH_NAMES[$month],
                'status' => $this->monthStatus($window, $now),
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
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'monthly_rankings',
                'selection_note' => 'Top-1 performing memecoin per chain bucket per month, by observed market-cap growth within the eligible universe. Not a prediction of future returns, not an investment recommendation.',
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
     * @return array<string,mixed>
     */
    private function fromRow(MonthlyRanking $row): array
    {
        // Works for a tracked Token (`token_id`) OR a denormalized
        // historically-researched champion (`champion_*`).
        $identity = $row->championIdentity();

        return [
            'chain_bucket' => $row->chain_bucket,
            'status' => $row->status,
            'token' => $identity,
            'performance' => $identity === null ? null : [
                'score' => $row->performance_score,
                'baseline_market_cap' => $row->baseline_market_cap,
                'peak_market_cap' => $row->peak_market_cap,
                'market_cap_growth_pct' => $row->market_cap_growth_pct,
                'peak_expansion_ratio' => $row->peak_expansion_ratio,
                'activity_score' => $row->activity_score,
                'observation_count' => $row->observation_count,
                'observation_coverage_ratio' => $row->observation_coverage_ratio,
            ],
            'source_type' => $row->source_type,
            'source_reference' => $row->source_reference,
            // Short list of {name, url, claim, published_at, credibility} — the
            // research provenance for a historically-backfilled champion.
            'source_evidence' => $row->source_evidence ?? [],
            'age_uncertain' => (bool) $row->age_uncertain,
            'confidence' => $row->confidence,
            'finalized_at' => $row->finalized_at?->toIso8601String(),
            'computed_at' => $row->computed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function synthesizeBucket(string $bucket, MonthWindow $window, CarbonImmutable $now): array
    {
        $status = match (true) {
            $window->isFuture($now) => MonthlyRanking::STATUS_FUTURE,
            $window->isCurrent($now) => MonthlyRanking::STATUS_PROVISIONAL,
            default => MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION,
        };

        return [
            'chain_bucket' => $bucket,
            'status' => $status,
            'token' => null,
            'performance' => null,
            'source_type' => null,
            'source_reference' => null,
            'source_evidence' => [],
            'age_uncertain' => false,
            'confidence' => null,
            'finalized_at' => null,
            'computed_at' => null,
        ];
    }
}
