<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyTrendingRanking;
use App\Services\Ranking\ChainBucket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/trending/history?date=YYYY-MM-DD&timeframe=6h|24h&chain=
 *
 * "Trending Yesterday" — reads `daily_trending_rankings` ONLY. It NEVER
 * recomputes from current state, never calls a provider. A token that trended
 * yesterday stays here even if it stopped trending today.
 *
 * Default date = yesterday. Rows ordered by `best_rank`.
 */
class TrendingHistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'timeframe' => ['sometimes', 'string', 'in:6h,24h'],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $now = CarbonImmutable::now();
        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->toDateString()
            : $now->subDay()->toDateString();
        $timeframe = $validated['timeframe'] ?? '6h';
        $chainFilter = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $limit = (int) ($validated['limit'] ?? 50);

        $query = DailyTrendingRanking::query()
            ->where('date', $date)
            ->where('timeframe', $timeframe)
            ->with(['token:id,chain_id,token_address'])
            ->orderBy('best_rank');

        if ($chainFilter !== null && $chainFilter !== '') {
            if (ChainBucket::isValid($chainFilter)) {
                $query->where('chain_bucket', $chainFilter);
            } else {
                $query->where('chain_id', $chainFilter);
            }
        }

        /** @var Collection<int, DailyTrendingRanking> $rankings */
        $rankings = $query->limit($limit)->get();

        $rows = $rankings->map(fn (DailyTrendingRanking $r): array => [
            'best_rank' => $r->best_rank,
            'best_score' => $r->best_score,
            'appearances' => $r->appearances,
            'timeframe' => $r->timeframe,
            'chain_bucket' => $r->chain_bucket,

            'token_id' => $r->token_id,
            'chain_id' => $r->chain_id,
            'token_address' => $r->token_address,
            'symbol' => $r->symbol,
            'name' => $r->name,
            'is_tracked' => $r->token_id !== null,

            'peak_market_cap' => $r->peak_market_cap,
            'peak_volume' => $r->peak_volume,
            'peak_liquidity' => $r->peak_liquidity,

            'trending_meta_slug' => $r->trending_meta_slug,
            'trending_meta_name' => $r->trending_meta_name,
            'first_seen_at' => $r->first_seen_at?->toIso8601String(),
            'last_seen_at' => $r->last_seen_at?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $date,
                'timeframe' => $timeframe,
                'chain' => $chainFilter,
                'count' => count($rows),
                'is_yesterday' => $date === $now->subDay()->toDateString(),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'daily_trending_rankings',
                'note' => 'Historical observations from the daily archive — not recomputed from current state.',
            ],
        ]);
    }
}
