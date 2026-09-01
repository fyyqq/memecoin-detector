<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChainActivity;
use App\Services\Ranking\ChainBucket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/memecoins/chain-activity
 *
 * "Chain Market Activity" — read-only, PostgreSQL only. Reads the materialised
 * `daily_chain_activity` table (today's row per bucket) and compares it to
 * yesterday's row for a `volume_change_pct` (null when there is no prior row).
 *
 * `total_volume_usd` is REPORTED volume — deduplicated token-level
 * representative-pair volume, never claimed organic. Never calls a provider.
 */
class ChainActivityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $yesterday = $now->subDay()->toDateString();

        $todayRows = DailyChainActivity::query()->where('date', $today)->get()->keyBy('chain_bucket');
        $yesterdayRows = DailyChainActivity::query()->where('date', $yesterday)->get()->keyBy('chain_bucket');

        // Fall back to the most recent computed row per bucket if today's run
        // hasn't happened yet.
        $latestRows = $todayRows->isEmpty()
            ? DailyChainActivity::query()
                ->whereIn('id', DailyChainActivity::query()->selectRaw('max(id) as id')->groupBy('chain_bucket')->pluck('id'))
                ->get()
                ->keyBy('chain_bucket')
            : $todayRows;

        $data = [];
        foreach (ChainBucket::ALL as $bucket) {
            /** @var DailyChainActivity|null $current */
            $current = $latestRows->get($bucket);
            /** @var DailyChainActivity|null $prior */
            $prior = $yesterdayRows->get($bucket);

            $currentVolume = $current !== null ? (float) $current->total_volume_usd : 0.0;
            $priorVolume = $prior !== null ? (float) $prior->total_volume_usd : null;

            $data[] = [
                'chain_bucket' => $bucket,
                'label' => ChainBucket::label($bucket),
                'total_volume_usd' => $current !== null ? $currentVolume : null,
                'total_liquidity_usd' => $current !== null ? (float) $current->total_liquidity_usd : null,
                'active_token_count' => $current !== null ? $current->active_token_count : 0,
                'top_token' => $current !== null && $current->top_token_address !== null ? [
                    'token_id' => $current->top_token_id,
                    'token_address' => $current->top_token_address,
                    'symbol' => $current->top_token_symbol,
                    'reported_volume_usd' => $current->top_token_volume,
                ] : null,
                'volume_change_pct' => ($priorVolume !== null && $priorVolume > 0.0 && $current !== null)
                    ? round((($currentVolume - $priorVolume) / $priorVolume) * 100.0, 2)
                    : null,
                'computed_at' => $current?->computed_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'date' => $today,
                'has_today' => ! $todayRows->isEmpty(),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'daily_chain_activity',
                'note' => 'Reported volume — deduplicated token-level representative-pair volume per chain bucket. Not claimed to be organic volume.',
            ],
        ]);
    }
}
