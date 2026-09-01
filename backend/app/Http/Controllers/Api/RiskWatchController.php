<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use App\Services\Risk\MainListDecision;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/risk-watch (Step 24).
 *
 * Read-only, PostgreSQL only — never calls a provider, never writes, never
 * screens. Returns tokens that ARE market-cap qualified (Step 19) but FAIL the
 * MAIN LIST risk screen: HIGH / CRITICAL / UNKNOWN risk, too young, insufficient
 * security data, or a hard filter. They are shown for transparency — never
 * hidden and never deleted.
 *
 * This is a RISK FILTER, not a "safe to invest" signal.
 */
class RiskWatchController extends Controller
{
    private const SEVERITY_RANK = [
        RiskSignal::SEVERITY_CRITICAL => 0,
        RiskSignal::SEVERITY_HIGH => 1,
        RiskSignal::SEVERITY_MEDIUM => 2,
        RiskSignal::SEVERITY_LOW => 3,
        RiskSignal::SEVERITY_NONE => 4,
    ];

    private const LEVEL_RANK = [
        RiskAssessment::LEVEL_CRITICAL => 0,
        RiskAssessment::LEVEL_HIGH => 1,
        RiskAssessment::LEVEL_UNKNOWN => 2,
        RiskAssessment::LEVEL_MEDIUM => 3,
        RiskAssessment::LEVEL_LOWER => 4,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $chain = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $limit = (int) ($validated['limit'] ?? 50);
        $now = CarbonImmutable::now();

        /** @var Collection<int, Token> $qualified */
        $qualified = Token::query()
            ->marketCapQualified($now)
            ->when($chain, fn ($q) => $q->where('chain_id', $chain))
            ->with(['latestSnapshot', 'riskAssessment.signals', 'latestTrending6h', 'latestTrending24h'])
            ->limit(500)
            ->get();

        $rows = $qualified
            ->map(function (Token $token) use ($now): ?array {
                $decision = MainListDecision::for($token, $now);
                if ($decision->eligible) {
                    return null;
                }

                return $this->row($token, $decision, $now);
            })
            ->filter()
            ->sort(function (array $a, array $b): int {
                $la = self::LEVEL_RANK[$a['risk_level']] ?? 9;
                $lb = self::LEVEL_RANK[$b['risk_level']] ?? 9;

                return $la === $lb ? ($b['_peak'] <=> $a['_peak']) : $la <=> $lb;
            })
            ->take($limit)
            ->map(function (array $row): array {
                unset($row['_peak']);

                return $row;
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'postgresql',
                'note' => 'Qualified by market cap, but failed one or more risk checks — shown for transparency. This is not a safe-to-invest signal.',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Token $token, MainListDecision $decision, CarbonImmutable $now): array
    {
        $snapshot = $token->latestSnapshot;
        /** @var RiskAssessment|null $assessment */
        $assessment = $token->riskAssessment;

        $peak = max((float) ($token->observed_peak_market_cap ?? 0), (float) ($token->historical_peak_value ?? 0));

        $failed = ($assessment !== null && $assessment->relationLoaded('signals') ? $assessment->signals : collect())
            ->filter(fn (RiskSignal $s): bool => $s->state === RiskSignal::STATE_BAD)
            ->sortBy(fn (RiskSignal $s): int => self::SEVERITY_RANK[$s->severity] ?? 9)
            ->map(fn (RiskSignal $s): array => [
                'signal' => $s->signal_key,
                'group' => $s->signal_group,
                'state' => $s->state,
                'value' => $s->value,
                'source' => $s->source,
                'severity' => $s->severity,
                'explanation' => $s->explanation,
            ])
            ->values()
            ->all();

        return [
            'id' => $token->id,
            'chain_id' => $token->chain_id,
            'token_address' => $token->token_address,
            'name' => $token->name,
            'symbol' => $token->symbol,
            'current_mc' => $snapshot?->market_cap,
            'peak_mc' => $peak > 0 ? $peak : null,
            'age_days' => $token->earliest_pair_created_at !== null
                ? round(($now->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 86_400, 2)
                : null,
            'risk_level' => $decision->riskLevel ?? RiskAssessment::LEVEL_UNKNOWN,
            'risk_score' => $decision->riskScore,
            'data_completeness' => $decision->dataCompleteness,
            'screened_at' => $assessment?->screened_at?->toIso8601String(),
            'reasons' => $decision->reasonLabels(),
            'failed_signals' => $failed,

            // Trending Tracking — a token can be TRENDING and on RISK WATCH at
            // the same time. Shown so a trending token that failed the risk
            // screen stays visible with its rank / timeframe / last-trending
            // time — never hidden.
            'trend' => $this->trend($token),

            '_peak' => $peak,
        ];
    }

    /**
     * The token's latest "Tracked Trending" state (6h + 24h), or null. Reads the
     * eager-loaded relations only.
     *
     * @return array<string,mixed>|null
     */
    private function trend(Token $token): ?array
    {
        $six = $token->relationLoaded('latestTrending6h') ? $token->latestTrending6h : null;
        $day = $token->relationLoaded('latestTrending24h') ? $token->latestTrending24h : null;

        if ($six === null && $day === null) {
            return null;
        }

        return [
            'tracked_trend_score_6h' => $six?->tracked_trend_score,
            'trend_rank_6h' => $six?->trend_rank,
            'tracked_trend_score_24h' => $day?->tracked_trend_score,
            'trend_rank_24h' => $day?->trend_rank,
            'last_trending_at' => ($six?->captured_at ?? $day?->captured_at)?->toIso8601String(),
        ];
    }
}
