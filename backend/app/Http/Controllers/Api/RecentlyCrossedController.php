<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QualificationEvent;
use App\Models\Token;
use App\Services\Historical\RecentlyCrossedQualifier;
use App\Services\Historical\SameTickerCollapser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/memecoins/recently-crossed
 *
 * The "🔥 Recently Crossed $5M" dashboard section. Read-only. Straight from
 * PostgreSQL — NEVER calls DexScreener, CoinGecko, GeckoTerminal or GoPlus,
 * never writes, never runs discovery, never creates a QualificationEvent.
 *
 * A token appears ONLY when it satisfies ALL of:
 *   - its REPRESENTATIVE "$5M crossing" (`qualification_events.crossed_at`,
 *     HISTORICAL_VERIFIED over CURRENT_OBSERVATION) is within the last
 *     `recent_crossing.window_days` (default 30) — the persisted crossing date,
 *     NEVER derived from the current market cap;
 *   - age ≤ 30d (pool age — a SEPARATE concept from "crossed within 30 days");
 *   - a verified/observed peak market cap in `[$5M, $1B)` (real market cap,
 *     never FDV; floor inclusive, ceiling exclusive);
 *   - it passes every deterministic quality gate in
 *     {@see RecentlyCrossedQualifier} — discovery freshness, risk screen
 *     (LOWER/MEDIUM, no critical security failure), holder participation vs
 *     current MC, 24h volume vs current MC, and liquidity.
 *
 * A token whose current MC is now BELOW $5M can still appear (COOLED) — the
 * floor is a peak rule. A token that crossed $5M but fails a quality gate does
 * NOT appear (see the empty-state copy).
 */
class RecentlyCrossedController extends Controller
{
    public function __construct(private readonly RecentlyCrossedQualifier $qualifier) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;

        $windowDays = (int) config('dexscreener.recent_crossing.window_days', 30);

        $now = CarbonImmutable::now();
        $windowStart = $now->subDays($windowDays);

        // Shared DB-level filters (recent pool age, discovery freshness, peak in
        // band, a crossing event in the window). The precise
        // representative-crossing-in-window check + the deterministic quality
        // gates run in PHP below.
        $tokens = Token::query()
            ->recentlyCrossedListingCandidate($now)
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            ->withCount('marketSnapshots')
            ->with(['latestSnapshot', 'historicalPeakEvidence', 'qualificationEvents', 'riskAssessment.signals'])
            ->get();

        // Every token that clears the window check + all deterministic gates.
        $qualified = $tokens->filter(function (Token $token) use ($now, $windowStart): bool {
            $event = $token->representativeQualificationEvent();
            if ($event === null || $event->crossed_at === null) {
                return false;
            }

            // The REPRESENTATIVE crossing must itself be inside the window.
            if ($event->crossed_at->getTimestamp() < $windowStart->getTimestamp()) {
                return false;
            }

            // Deterministic quality + red-flag gates. PostgreSQL-only.
            return $this->qualifier->evaluate($token, $now)->qualifies;
        });

        // Collapse ticker/name-squatting duplicates to one row per coin identity.
        $rows = SameTickerCollapser::winners($qualified)
            ->map(function (Token $token) use ($now): array {
                /** @var QualificationEvent $event */
                $event = $token->representativeQualificationEvent();
                $snapshot = $token->latestSnapshot;
                $qualificationPeak = $this->qualificationPeak($token);

                return [
                    'id' => $token->id,
                    'chain_id' => $token->chain_id,
                    'token_address' => $token->token_address,
                    'name' => $token->name,
                    'symbol' => $token->symbol,
                    'current_market_cap' => $snapshot?->market_cap,
                    'observed_peak_market_cap' => $token->observed_peak_market_cap,
                    'qualification_peak' => $qualificationPeak,
                    'crossed_at' => $event->crossed_at->toIso8601String(),
                    'crossing_type' => $event->type,
                    'crossing_market_cap_value' => $event->market_cap_value,
                    'status' => ($snapshot?->market_cap ?? 0.0) >= $this->floor() ? 'ACTIVE' : 'COOLED',
                    'age_days' => $this->ageDays($token, $now),
                    'last_observed_at' => $token->last_observed_at?->toIso8601String(),
                    '_crossed_at_ts' => $event->crossed_at->getTimestamp(),
                ];
            })
            ->sortByDesc('_crossed_at_ts')
            ->map(function (array $row): array {
                unset($row['_crossed_at_ts']);

                return $row;
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'days' => $windowDays,
                'count' => count($rows),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'postgresql',
                'note' => 'Memecoins whose verified/observed $5M crossing occurred within the last '
                    .$windowDays.' days AND that pass the current quality gates (risk screen, holder '
                    .'participation, 24h volume vs market cap, liquidity, active discovery). A token '
                    .'below $5M now can still appear — it previously crossed the threshold.',
            ],
        ]);
    }

    private function floor(): float
    {
        return (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
    }

    private function qualificationPeak(Token $token): ?float
    {
        $evidence = $token->historicalPeakEvidence;
        $min = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $max = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');

        if ($evidence !== null && $evidence->qualifies($min, $max)) {
            return $evidence->peak_value_usd;
        }

        if ($token->observed_peak_market_cap !== null
            && $token->observed_peak_market_cap >= $min
            && $token->observed_peak_market_cap < $max) {
            return $token->observed_peak_market_cap;
        }

        return $token->historical_peak_value;
    }

    private function ageDays(Token $token, CarbonImmutable $now): ?float
    {
        if ($token->earliest_pair_created_at === null) {
            return null;
        }

        return round(($now->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 86_400, 2);
    }
}
