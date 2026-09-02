<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HistoricalPeakEvidence;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/memecoins/recently-crossed
 *
 * Read-only. Straight from PostgreSQL — NEVER calls DexScreener, CoinGecko or
 * GeckoTerminal, never writes, never runs discovery, never creates a
 * QualificationEvent.
 *
 * Returns currently-qualified tokens: age <= 30d AND a verified/observed peak
 * `$5M <= peak < $1B` (floor inclusive, ceiling EXCLUSIVE) whose REPRESENTATIVE
 * "$5M crossing" (HISTORICAL_VERIFIED over CURRENT_OBSERVATION) happened within
 * the window (default 48h, `?hours=` up to a safe max of 168). Newest crossing
 * first.
 *
 * A token whose current MC is now BELOW $5M can still appear — the floor is a
 * peak rule and this endpoint is about the crossing, not the current price.
 */
class RecentlyCrossedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $maxHours = (int) config('dexscreener.recent_crossing.max_hours');

        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:'.$maxHours],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $hours = (int) ($validated['hours'] ?? config('dexscreener.recent_crossing.hours'));
        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;

        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $peakMin = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $peakMax = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');

        $now = CarbonImmutable::now();
        $ageCutoff = $now->subDays($maxAgeDays);
        $windowStart = $now->subHours($hours);

        $tokens = Token::query()
            ->whereNotNull('earliest_pair_created_at')
            ->where('earliest_pair_created_at', '>=', $ageCutoff)
            ->where(function (Builder $query) use ($peakMin): void {
                $query->where('observed_peak_market_cap', '>=', $peakMin)
                    ->orWhere(function (Builder $q) use ($peakMin): void {
                        $q->where('historical_peak_status', HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED)
                            ->where('historical_peak_value', '>=', $peakMin);
                    });
            })
            ->whereRaw(
                'GREATEST(COALESCE(observed_peak_market_cap, 0), COALESCE(historical_peak_value, 0)) < ?',
                [$peakMax],
            )
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            // At least one crossing exists in the window — the precise
            // representative-in-window check happens in PHP below.
            ->whereHas('qualificationEvents', fn (Builder $q) => $q->where('crossed_at', '>=', $windowStart))
            ->with(['latestSnapshot', 'historicalPeakEvidence', 'qualificationEvents'])
            ->get();

        $rows = $tokens
            ->map(function (Token $token) use ($now): ?array {
                $event = $token->representativeQualificationEvent();
                if ($event === null || $event->crossed_at === null) {
                    return null;
                }

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
            ->filter()
            // Keep only tokens whose REPRESENTATIVE crossing is inside the window.
            ->filter(fn (array $row): bool => $row['_crossed_at_ts'] >= $windowStart->getTimestamp())
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
                'hours' => $hours,
                'count' => count($rows),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'postgresql',
                'note' => 'Tokens whose verified/observed $5M crossing occurred within the window. A token below $5M now can still appear — it previously crossed the threshold.',
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
