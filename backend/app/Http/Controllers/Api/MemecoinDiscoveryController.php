<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IngestionRun;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MemecoinDiscoveryController extends Controller
{
    /**
     * GET /api/memecoins/discover
     *
     * Runs the Sprint 1 discovery pipeline: discovers candidates, persists a
     * Token + MarketSnapshot for every age-eligible one, updates each token's
     * observed peak market cap, and returns the tokens that qualify
     * (age <= 30 days AND observed peak market cap >= threshold).
     */
    public function __invoke(Request $request, DexScreenerDiscoveryService $discovery): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $maxLimit = (int) config('dexscreener.limits.max_result_limit');
        $defaultLimit = (int) config('dexscreener.limits.default_result_limit');

        $limit = (int) ($validated['limit'] ?? $defaultLimit);
        $limit = max(1, min($limit, $maxLimit));

        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;

        try {
            $result = $discovery->discover($chain, $limit, IngestionRun::TRIGGER_MANUAL);
        } catch (Throwable $e) {
            Log::error('Memecoin discovery endpoint failed', ['error' => $e->getMessage()]);

            // Safe JSON error — never a stack trace. The failed run is recorded
            // in ingestion_runs by the service.
            return response()->json([
                'error' => 'Discovery run failed. See ingestion_runs for details.',
                'meta' => ['retrieved_at' => CarbonImmutable::now()->toIso8601String()],
            ], 503);
        }

        return response()->json([
            'data' => array_map(
                fn ($candidate) => $candidate->toArray(),
                $result->candidates,
            ),
            'meta' => [
                'count' => count($result->candidates),
                'limit' => $limit,
                'chain' => $chain,
                'ingestion_run_id' => $result->ingestionRunId,
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
                'filters' => [
                    'max_age_days' => (int) config('dexscreener.filters.max_age_days'),
                    'observed_peak_market_cap_min_usd' => (int) config('dexscreener.filters.observed_peak_market_cap_min_usd'),
                ],
                'coverage_note' => 'Activity- and keyword-driven sample; not an exhaustive token census.',
                'observed_peak_note' => 'observed_peak_market_cap is the highest market cap captured by our own snapshots since observed_since — not a guaranteed lifetime high.',
                'diagnostics' => $result->diagnostics,
                'not_qualified_sample' => $result->notQualifiedSample,
            ],
        ]);
    }
}
