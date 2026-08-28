<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemecoinResource;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemecoinListController extends Controller
{
    /**
     * GET /api/memecoins
     *
     * Read-only "30-Day Leaders" list, straight from PostgreSQL. This endpoint
     * never calls DexScreener, never writes, never runs discovery — the
     * scheduled `memecoins:discover` command is the only writer.
     *
     * Qualified = earliest_pair_created_at within `max_age_days` of now
     * AND observed_peak_market_cap >= threshold. Sorted by observed peak desc.
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
            ->where('observed_peak_market_cap', '>=', $peakMin)
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            ->with('latestSnapshot')
            ->orderByDesc('observed_peak_market_cap')
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
