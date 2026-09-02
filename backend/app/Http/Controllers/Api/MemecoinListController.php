<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemecoinResource;
use App\Models\Token;
use App\Services\Risk\MainListDecision;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class MemecoinListController extends Controller
{
    /**
     * GET /api/memecoins — the MAIN LIST.
     *
     * Read-only, PostgreSQL only. Never calls DexScreener / CoinGecko /
     * GeckoTerminal / GoPlus, never writes, never runs discovery or screening.
     *
     * A row appears here only when it is BOTH:
     *   1. market-cap qualified (Step 19 — age <= max_age_days AND a
     *      VERIFIED / OBSERVED peak in [$5M, $1B]; HISTORICAL_ESTIMATE and
     *      UNKNOWN never qualify); AND
     *   2. it passes the Step 24 risk screen — mature enough
     *      (>= MEMECOIN_MAIN_MIN_AGE_HOURS), risk_level in {LOWER, MEDIUM},
     *      data completeness >= minimum, and no hard filter tripped.
     *
     * A token that is market-cap qualified but fails (2) is excluded from this
     * list; its full risk assessment is still on its detail page.
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

        $now = CarbonImmutable::now();

        /** @var Collection<int, Token> $qualified */
        $qualified = Token::query()
            ->marketCapQualified($now)
            ->when($chain, fn ($q) => $q->where('chain_id', $chain))
            ->with([
                'latestSnapshot',
                'historicalPeakEvidence',
                'qualificationEvents',
                'riskAssessment.signals',
            ])
            ->limit(500)
            ->get();

        // Partition on the shared MAIN LIST / RISK WATCH decision.
        $mainList = $qualified->filter(fn (Token $token): bool => MainListDecision::for($token, $now)->eligible);

        $sorted = $this->sort($mainList, $sort)->take($limit)->values();

        return MemecoinResource::collection($sorted)->additional([
            'meta' => [
                'count' => $sorted->count(),
                'retrieved_at' => $now->toIso8601String(),
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

    /**
     * @param  Collection<int, Token>  $tokens
     * @return Collection<int, Token>
     */
    private function sort(Collection $tokens, string $sort): Collection
    {
        if ($sort === self::SORT_RECENT_CROSSING) {
            return $tokens->sort(function (Token $a, Token $b): int {
                $ax = $a->representativeQualificationEvent()?->crossed_at?->getTimestamp();
                $bx = $b->representativeQualificationEvent()?->crossed_at?->getTimestamp();
                if ($ax === $bx) {
                    return $a->id <=> $b->id;
                }
                if ($ax === null) {
                    return 1;
                }
                if ($bx === null) {
                    return -1;
                }

                return $bx <=> $ax;
            })->values();
        }

        return $tokens->sort(function (Token $a, Token $b): int {
            $ap = max((float) ($a->observed_peak_market_cap ?? 0), (float) ($a->historical_peak_value ?? 0));
            $bp = max((float) ($b->observed_peak_market_cap ?? 0), (float) ($b->historical_peak_value ?? 0));

            return $ap === $bp ? $a->id <=> $b->id : $bp <=> $ap;
        })->values();
    }
}
