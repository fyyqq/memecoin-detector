<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiskAssessment;
use App\Models\Token;
use App\Models\TrendingSnapshot;
use App\Services\Ranking\ChainBucket;
use App\Services\Risk\MainListDecision;
use App\Services\Trending\MemecoinClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/trending?timeframe=6h|24h&chain=
 *
 * "Top Trending Memecoins" — read-only, PostgreSQL only. Returns ONLY the top
 * currently-trending, NEWLY-LAUNCHED memecoins that pass our approved filters:
 *
 *   is_memecoin_candidate == TRUE
 *   AND age <= 30 days (earliest DEX pool; unknown -> excluded)
 *   AND CURRENT market cap in [$5M, $200M]
 *   AND volume > 0 AND liquidity > 0
 *
 * The filters are applied at collection AND re-checked here. `limit` defaults to
 * `config('trending.top_n')` (10) and is capped at `config('trending.top_max')`
 * (20) — the result is intentionally small, so there is no pagination.
 *
 * This is "Latest Trending" — the most recent captured trending state. The
 * historical archive is `GET /api/memecoins/trending/history`. The MAIN LIST
 * (`GET /api/memecoins`) is a different concept (observed/verified PEAK
 * qualification + risk screen) and is untouched.
 *
 * "Tracked Trending" — this is NOT DexScreener's proprietary `trendingScore`.
 * Trending is attention, not safety. Each row carries its risk level; a stale
 * scan shows `risk_check_stale = true` and is never silently treated as safe.
 *
 * Never calls DexScreener / a security provider. Never opens a WebSocket. Never
 * writes.
 */
class TrendingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $topN = max(1, (int) config('trending.top_n', 10));
        $topMax = max($topN, (int) config('trending.top_max', 20));

        $validated = $request->validate([
            'timeframe' => ['sometimes', 'string', 'in:6h,24h'],
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.$topMax],
        ]);

        $timeframe = $validated['timeframe'] ?? TrendingSnapshot::TIMEFRAME_6H;
        $chainFilter = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $limit = min($topMax, (int) ($validated['limit'] ?? $topN));
        $now = CarbonImmutable::now();

        $staleHours = (int) config('trending.risk_stale_hours', 6);
        $minMc = (float) config('trending.eligibility.min_current_market_cap');
        $maxMc = (float) config('trending.eligibility.max_current_market_cap');
        $maxAgeDays = (int) config('trending.eligibility.max_age_days');

        $latestBucket = TrendingSnapshot::query()
            ->where('timeframe', $timeframe)
            ->max('capture_bucket');

        if ($latestBucket === null) {
            return $this->empty($timeframe, $chainFilter, $topN, $now);
        }

        /** @var Collection<int, TrendingSnapshot> $snapshots */
        $snapshots = TrendingSnapshot::query()
            ->where('timeframe', $timeframe)
            ->where('capture_bucket', (int) $latestBucket)
            ->with(['token.riskAssessment.signals'])
            ->orderBy('trend_rank')
            ->get()
            // Read-time eligibility guard — defensive re-check of the filters
            // the collector applied (handles a stale capture or a token that
            // aged out since).
            ->filter(function (TrendingSnapshot $s) use ($now, $minMc, $maxMc, $maxAgeDays): bool {
                if ($s->is_memecoin_candidate === MemecoinClassifier::FALSE) {
                    return false;
                }
                if ($s->market_cap === null || $s->market_cap < $minMc || $s->market_cap > $maxMc) {
                    return false;
                }
                if (($s->liquidity_usd ?? 0.0) <= 0.0 || ($s->volume_usd ?? 0.0) <= 0.0) {
                    return false;
                }
                $createdAt = $s->token?->earliest_pair_created_at ?? $s->pair_created_at;
                if ($createdAt === null) {
                    return false;
                }

                return ($now->getTimestamp() - $createdAt->getTimestamp()) / 86_400.0 <= $maxAgeDays;
            })
            ->filter(function (TrendingSnapshot $s) use ($chainFilter): bool {
                if ($chainFilter === null || $chainFilter === '') {
                    return true;
                }
                if (ChainBucket::isValid($chainFilter)) {
                    return ChainBucket::forChain($s->chain_id) === $chainFilter;
                }

                return mb_strtolower($s->chain_id) === $chainFilter;
            })
            ->sortBy([['tracked_trend_score', 'desc'], ['token_address', 'asc']])
            ->take($limit)
            ->values();

        // One query: which of these tracked tokens are market-cap qualified.
        $tokenIds = $snapshots->pluck('token_id')->filter()->unique()->all();
        $qualifiedIds = $tokenIds === []
            ? []
            : Token::query()->whereIn('id', $tokenIds)->marketCapQualified($now)->pluck('id')->all();

        $rows = $snapshots
            ->values()
            ->map(fn (TrendingSnapshot $s, int $i): array => $this->row($s, $i + 1, $now, $staleHours, $qualifiedIds))
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'timeframe' => $timeframe,
                'chain' => $chainFilter,
                'count' => count($rows),
                'top_n' => $topN,
                'top_max' => $topMax,
                'capture_bucket' => (int) $latestBucket,
                'captured_at' => CarbonImmutable::createFromTimestamp((int) $latestBucket)->toIso8601String(),
                'refresh_minutes' => (int) config('trending.refresh_minutes', 5),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'dexscreener_meta',
                'filters' => [
                    'memecoin_only' => true,
                    'max_age_days' => $maxAgeDays,
                    'min_current_market_cap' => (int) $minMc,
                    'max_current_market_cap' => (int) $maxMc,
                    'volume_required' => true,
                    'liquidity_required' => true,
                ],
                'note' => 'Top currently-trending newly-launched memecoins. Tracked Trending is our transparent internal ranking from DexScreener market signals, not DexScreener\'s proprietary trendingScore. Trending is attention, not safety.',
            ],
        ]);
    }

    /**
     * @param  int  $rank  1-based position in this (filtered, top-N) response
     * @param  list<int>  $qualifiedIds  token ids that are market-cap qualified
     * @return array<string,mixed>
     */
    private function row(TrendingSnapshot $s, int $rank, CarbonImmutable $now, int $staleHours, array $qualifiedIds): array
    {
        $token = $s->relationLoaded('token') ? $s->token : null;
        /** @var RiskAssessment|null $assessment */
        $assessment = $token?->relationLoaded('riskAssessment') ? $token->riskAssessment : null;

        $riskLevel = $assessment?->risk_level;
        $checkedAt = $assessment?->screened_at;
        $stale = $checkedAt === null || $checkedAt->lessThan($now->subHours($staleHours));

        $createdAt = $token?->earliest_pair_created_at ?? $s->pair_created_at;
        $ageDays = $createdAt !== null
            ? round(($now->getTimestamp() - $createdAt->getTimestamp()) / 86_400.0, 2)
            : null;

        // MAIN LIST membership — is this trending token also investable-research?
        $mainListEligible = false;
        if ($token !== null && in_array($token->id, $qualifiedIds, true)) {
            $mainListEligible = MainListDecision::for($token, $now)->eligible;
        }

        return [
            'rank' => $rank,
            'trend_rank' => $s->trend_rank,
            'tracked_trend_score' => $s->tracked_trend_score,
            'trend_components' => $s->trend_score_components,
            'trend_appearances' => $s->trend_appearances,
            'timeframe' => $s->timeframe,
            'is_memecoin_candidate' => $s->is_memecoin_candidate,

            'token_id' => $token?->id,
            'chain_id' => $s->chain_id,
            'chain_bucket' => ChainBucket::forChain($s->chain_id),
            'token_address' => $s->token_address,
            'pair_address' => $s->pair_address,
            'dex_id' => $s->dex_id,
            'symbol' => $s->symbol,
            'name' => $s->name,

            'age_days' => $ageDays,
            'market_cap' => $s->market_cap,
            'liquidity_usd' => $s->liquidity_usd,
            'volume_usd' => $s->volume_usd,
            'price_change_pct' => $s->price_change_pct,
            'transaction_count' => $s->transaction_count,

            'trending_meta_slug' => $s->trending_meta_slug,
            'trending_meta_name' => $s->trending_meta_name,
            'captured_at' => $s->captured_at?->toIso8601String(),

            // Risk is SEPARATE from trending — shown, never used to hide the row.
            'risk_level' => $riskLevel,
            'risk_score' => $assessment?->risk_score,
            'risk_checked_at' => $checkedAt?->toIso8601String(),
            'risk_check_stale' => $stale,
            'is_tracked' => $token !== null,
            'main_list_eligible' => $mainListEligible,
        ];
    }

    private function empty(string $timeframe, ?string $chain, int $topN, CarbonImmutable $now): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => [
                'timeframe' => $timeframe,
                'chain' => $chain,
                'count' => 0,
                'top_n' => $topN,
                'top_max' => max($topN, (int) config('trending.top_max', 20)),
                'capture_bucket' => null,
                'captured_at' => null,
                'refresh_minutes' => (int) config('trending.refresh_minutes', 5),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'dexscreener_meta',
                'filters' => [
                    'memecoin_only' => true,
                    'max_age_days' => (int) config('trending.eligibility.max_age_days'),
                    'min_current_market_cap' => (int) config('trending.eligibility.min_current_market_cap'),
                    'max_current_market_cap' => (int) config('trending.eligibility.max_current_market_cap'),
                    'volume_required' => true,
                    'liquidity_required' => true,
                ],
                'note' => 'No trending capture recorded yet.',
            ],
        ]);
    }
}
