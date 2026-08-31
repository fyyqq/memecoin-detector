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
     * Qualified = age <= max_age_days AND a VERIFIED / OBSERVED market cap has
     * EVER reached the threshold, via one of:
     *   - observed_peak_market_cap >= threshold                     (CURRENT_OBSERVATION)
     *   - historical_peak_status = HISTORICAL_VERIFIED
     *       with historical_peak_value >= threshold                 (CoinGecko-verified)
     *
     * HISTORICAL_ESTIMATE (FDV basis) and UNKNOWN are NOT returned — an
     * estimated FDV is not a verified market cap. The estimate is still stored
     * (`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`) and
     * shown on the detail page as a clearly-labelled secondary signal.
     *
     * Sorted by the qualifying market cap (observed or verified), desc.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $maxLimit = (int) config('dexscreener.limits.max_result_limit');
        $defaultLimit = (int) config('dexscreener.limits.default_result_limit');

        $limit = max(1, min((int) ($validated['limit'] ?? $defaultLimit), $maxLimit));
        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;

        $ageCutoff = CarbonImmutable::now()->subDays($maxAgeDays);

        $tokens = Token::query()
            ->whereNotNull('earliest_pair_created_at')
            ->where('earliest_pair_created_at', '>=', $ageCutoff)
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
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            ->with(['latestSnapshot', 'historicalPeakEvidence'])
            ->orderByRaw('GREATEST(COALESCE(observed_peak_market_cap, 0), COALESCE(historical_peak_value, 0)) DESC')
            ->limit($limit)
            ->get();

        return MemecoinResource::collection($tokens)->additional([
            'meta' => [
                'count' => $tokens->count(),
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
                'filters' => [
                    'max_age_days' => $maxAgeDays,
                    'observed_peak_market_cap_min_usd' => (int) $peakMin,
                ],
            ],
        ]);
    }
}
