<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemecoinResource;
use App\Models\HistoricalPeakEvidence;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemecoinListController extends Controller
{
    /**
     * GET /api/memecoins
     *
     * Read-only "30-Day Leaders" list, straight from PostgreSQL. This endpoint
     * never calls DexScreener, CoinGecko or GeckoTerminal, never writes, never
     * runs discovery — the scheduled `memecoins:discover` command is the only
     * writer.
     *
     * Qualified = age <= max_age_days AND a VERIFIED / OBSERVED market-cap peak
     * that sits in [$5M, $200M]:
     *   - the FLOOR is cleared by observed_peak_market_cap >= $5M
     *     (CURRENT_OBSERVATION) or HISTORICAL_VERIFIED with
     *     historical_peak_value >= $5M (CoinGecko-verified);
     *   - the CEILING applies to the greatest verified/observed peak
     *     (GREATEST(observed_peak, historical_peak_value)) <= $200M — a token
     *     that ever printed a higher peak is excluded even if its current MC is
     *     far lower.
     *
     * A token whose CURRENT MC has dumped below $5M STAYS in the list if it
     * already cleared the floor — the lower bound is a peak rule.
     *
     * HISTORICAL_ESTIMATE (FDV basis) and UNKNOWN are NOT returned — an
     * estimated FDV is not a verified market cap. The estimate is still stored
     * (`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`) and
     * shown on the detail page as a clearly-labelled secondary signal.
     *
     * Default sort is by the qualifying market cap (observed or verified), desc
     * — a stable leaderboard. `?sort=recent_crossing` re-orders by the token's
     * representative "$5M crossing" timestamp, newest first (tokens with no
     * recorded crossing last). The default is deliberately NOT recent_crossing:
     * the dedicated "Recently Crossed $5M" section / endpoint already serves the
     * recency view, and flipping the main leaderboard's order would disorient
     * existing dashboard users.
     */
    public const SORT_PEAK_MARKET_CAP = 'peak_market_cap';

    public const SORT_RECENT_CROSSING = 'recent_crossing';

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sort' => ['sometimes', 'string', 'in:'.self::SORT_PEAK_MARKET_CAP.','.self::SORT_RECENT_CROSSING],
        ]);

        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $peakMax = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');
        $maxLimit = (int) config('dexscreener.limits.max_result_limit');
        $defaultLimit = (int) config('dexscreener.limits.default_result_limit');
        $recentHours = (int) config('dexscreener.recent_crossing.hours');

        $limit = max(1, min((int) ($validated['limit'] ?? $defaultLimit), $maxLimit));
        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $sort = $validated['sort'] ?? self::SORT_PEAK_MARKET_CAP;

        $ageCutoff = CarbonImmutable::now()->subDays($maxAgeDays);

        // Representative crossing = strongest recorded crossing (HISTORICAL_VERIFIED
        // over CURRENT_OBSERVATION). Used for ?sort=recent_crossing.
        $representativeCrossedAt = 'coalesce('
            .'(select qe.crossed_at from qualification_events qe where qe.token_id = tokens.id '
            ."and qe.type = 'HISTORICAL_VERIFIED' limit 1), "
            .'(select qe.crossed_at from qualification_events qe where qe.token_id = tokens.id '
            ."and qe.type = 'CURRENT_OBSERVATION' limit 1))";

        $tokens = Token::query()
            ->whereNotNull('earliest_pair_created_at')
            ->where('earliest_pair_created_at', '>=', $ageCutoff)
            // FLOOR — at least one verified/observed source cleared $5M.
            ->where(function (Builder $query) use ($peakMin): void {
                // CURRENT_OBSERVATION — our own DexScreener snapshot saw MC >= $5M.
                $query->where('observed_peak_market_cap', '>=', $peakMin)
                    // HISTORICAL_VERIFIED — CoinGecko verified historical MC >= $5M.
                    // (historical_peak_value only ever holds a verified/observed
                    // market cap; an FDV estimate lives in a separate column.)
                    ->orWhere(function (Builder $q) use ($peakMin): void {
                        $q->where('historical_peak_status', HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED)
                            ->where('historical_peak_value', '>=', $peakMin);
                    });
            })
            // CEILING — the greatest verified/observed peak must be <= $200M.
            ->whereRaw(
                'GREATEST(COALESCE(observed_peak_market_cap, 0), COALESCE(historical_peak_value, 0)) <= ?',
                [$peakMax],
            )
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            ->with(['latestSnapshot', 'historicalPeakEvidence', 'qualificationEvents'])
            ->when(
                $sort === self::SORT_RECENT_CROSSING,
                fn ($query) => $query->orderByRaw("({$representativeCrossedAt}) DESC NULLS LAST"),
                fn ($query) => $query->orderByRaw('GREATEST(COALESCE(observed_peak_market_cap, 0), COALESCE(historical_peak_value, 0)) DESC'),
            )
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return MemecoinResource::collection($tokens)->additional([
            'meta' => [
                'count' => $tokens->count(),
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
                'sort' => $sort,
                'recent_crossing_hours' => $recentHours,
                'filters' => [
                    'max_age_days' => $maxAgeDays,
                    'observed_peak_market_cap_min_usd' => (int) $peakMin,
                    'observed_peak_market_cap_max_usd' => (int) $peakMax,
                ],
            ],
        ]);
    }
}
