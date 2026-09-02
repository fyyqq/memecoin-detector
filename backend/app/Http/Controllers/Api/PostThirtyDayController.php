<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/post-30-day
 *
 * The "📈 Post-30-Day Memecoins" dashboard section — memecoins that were
 * PREVIOUSLY approved by the "🔥 Recently Crossed $5M" flow
 * (`recently_crossed_qualified_at` stamped by `memecoins:mark-recently-crossed`,
 * never cleared) and whose pool age has now moved BEYOND the 30-day new-token
 * window (`earliest_pair_created_at`, the same age semantics used everywhere).
 *
 * This is a CONTINUATION list, not a new qualification system:
 *   - it never re-runs discovery and never calls DexScreener / CoinGecko /
 *     GeckoTerminal / GoPlus;
 *   - a token stays here after it dumps below $5M, loses discovery freshness, or
 *     is re-screened HIGH/CRITICAL — the historical approval is preserved;
 *   - current observable metrics + the current risk level are still shown, so
 *     "previously approved" is never read as "permanently safe".
 *
 * Recently Crossed requires age <= 30d and this list requires age > 30d, so a
 * token can never appear in both at once.
 *
 * Read-only. PostgreSQL only. Never writes.
 */
class PostThirtyDayController extends Controller
{
    private const SORTS = ['market_cap', 'volume', 'peak_market_cap', 'age', 'liquidity', 'holders'];

    private const DIRECTIONS = ['asc', 'desc'];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTS)],
            'direction' => ['sometimes', 'string', 'in:'.implode(',', self::DIRECTIONS)],
        ]);

        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $sort = $validated['sort'] ?? 'peak_market_cap';
        $direction = $validated['direction'] ?? 'desc';

        $maxAgeDays = (int) config('dexscreener.filters.max_age_days');
        $floor = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $now = CarbonImmutable::now();

        /** @var Collection<int, Token> $tokens */
        $tokens = Token::query()
            ->postThirtyDayTracked($now)
            ->when($chain, fn ($query) => $query->where('chain_id', $chain))
            ->with(['latestSnapshot', 'historicalPeakEvidence', 'qualificationEvents', 'riskAssessment.signals'])
            ->limit(500)
            ->get();

        $rows = $this->sort(
            $tokens->map(fn (Token $token): array => $this->row($token, $now, $floor)),
            $sort,
            $direction,
        )->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'postgresql',
                'sort' => $sort,
                'direction' => $direction,
                'age_threshold_days' => $maxAgeDays,
                'sorts' => self::SORTS,
                'note' => 'Memecoins previously approved by the "Recently Crossed $5M" flow whose pool '
                    .'is now older than '.$maxAgeDays.' days. Historical approval is preserved even if '
                    .'the token later fell below $5M or its risk level rose — the current risk state is '
                    .'shown alongside. This section is historical tracking, not a guarantee of safety.',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Token $token, CarbonImmutable $now, float $floor): array
    {
        $snapshot = $token->latestSnapshot;
        $currentMc = $snapshot?->market_cap;
        $peak = max(
            (float) ($token->observed_peak_market_cap ?? 0.0),
            (float) ($token->historical_peak_value ?? 0.0),
        );
        $event = $token->representativeQualificationEvent();
        $assessment = $token->riskAssessment;

        return [
            'id' => $token->id,
            'chain_id' => $token->chain_id,
            'token_address' => $token->token_address,
            'name' => $token->name,
            'symbol' => $token->symbol,
            'age_days' => $this->ageDays($token, $now),
            'current_market_cap' => $currentMc,
            'observed_peak_market_cap' => $token->observed_peak_market_cap,
            'peak_market_cap' => $peak > 0.0 ? $peak : null,
            'volume_h24' => $snapshot?->volume_h24,
            'liquidity_usd' => $snapshot?->liquidity_usd,
            'holder_count' => $this->holderCount($token),
            'risk_level' => $assessment?->risk_level,
            'risk_score' => $assessment?->risk_score,
            'risk_status' => $assessment?->screening_status ?? 'pending',
            'status' => ($currentMc ?? 0.0) >= $floor ? 'ACTIVE' : 'COOLED',
            'approved_at' => $token->recently_crossed_qualified_at?->toIso8601String(),
            'crossed_at' => $event?->crossed_at?->toIso8601String(),
            'crossing_type' => $event?->type,
            'days_to_cross' => $this->daysToCross($token, $event?->crossed_at),
            'last_observed_at' => $token->last_observed_at?->toIso8601String(),
        ];
    }

    /**
     * Deterministic sort. Nulls always sort last regardless of direction; ties
     * fall through to peak market cap, then the stable token id.
     *
     * @param  Collection<int, array<string,mixed>>  $rows
     * @return Collection<int, array<string,mixed>>
     */
    private function sort(Collection $rows, string $sort, string $direction): Collection
    {
        $key = match ($sort) {
            'market_cap' => 'current_market_cap',
            'volume' => 'volume_h24',
            'age' => 'age_days',
            'liquidity' => 'liquidity_usd',
            'holders' => 'holder_count',
            default => 'peak_market_cap',
        };
        $desc = $direction === 'desc';

        return $rows->sort(function (array $a, array $b) use ($key, $desc): int {
            $av = $a[$key];
            $bv = $b[$key];

            if ($av === null && $bv === null) {
                return $this->tieBreak($a, $b, $desc);
            }
            if ($av === null) {
                return 1;
            }
            if ($bv === null) {
                return -1;
            }

            $cmp = $av <=> $bv;
            if ($cmp !== 0) {
                return $desc ? -$cmp : $cmp;
            }

            return $this->tieBreak($a, $b, $desc);
        });
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function tieBreak(array $a, array $b, bool $desc): int
    {
        $pa = (float) ($a['peak_market_cap'] ?? 0.0);
        $pb = (float) ($b['peak_market_cap'] ?? 0.0);
        if ($pa !== $pb) {
            return $desc ? $pb <=> $pa : $pa <=> $pb;
        }

        return $a['id'] <=> $b['id'];
    }

    private function ageDays(Token $token, CarbonImmutable $now): ?float
    {
        if ($token->earliest_pair_created_at === null) {
            return null;
        }

        return round(($now->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 86_400, 2);
    }

    private function daysToCross(Token $token, ?CarbonImmutable $crossedAt): ?float
    {
        if ($crossedAt === null || $token->earliest_pair_created_at === null) {
            return null;
        }

        $days = ($crossedAt->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 86_400;

        return $days >= 0 ? round($days, 1) : null;
    }

    private function holderCount(Token $token): ?int
    {
        $assessment = $token->riskAssessment;
        if ($assessment === null || ! $assessment->relationLoaded('signals')) {
            return null;
        }

        $signal = $assessment->signals->firstWhere('signal_key', 'holder_count');
        if ($signal === null || $signal->state !== RiskSignal::STATE_MEASURED || $signal->numeric_value === null) {
            return null;
        }

        return (int) $signal->numeric_value;
    }
}
