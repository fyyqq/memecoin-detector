<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use App\Services\Trending\MarketIntegrityGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/memecoins/top-volume?chain=
 *
 * "Top 5 Volume by Chain" — read-only, PostgreSQL only. Per chain bucket, the
 * top tokens by REPORTED 24h volume (each token's LATEST MarketSnapshot's
 * representative-pair `volume_h24` — one figure per token, never double-counted
 * across pools), after the {@see MarketIntegrityGate}.
 *
 * "Reported Volume" — the integrity gate removes obvious anomalies; it does NOT
 * certify the remaining volume as organic / real human volume. Ranking is
 * `volume_h24 DESC` among tokens passing the gate. Never calls a provider.
 */
class TopVolumeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chain' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $chainFilter = isset($validated['chain']) ? mb_strtolower($validated['chain']) : null;
        $perChain = (int) config('trending.volume.top_per_chain', 5);
        $activeHours = (int) config('trending.volume.active_within_hours', 48);
        $now = CarbonImmutable::now();
        $activeSince = $now->subHours($activeHours);
        $staleHours = (int) config('trending.risk_stale_hours', 6);

        /** @var Collection<int, Token> $tokens */
        $tokens = Token::query()
            ->whereHas('marketSnapshots', fn ($q) => $q->where('observed_at', '>=', $activeSince))
            ->with(['latestSnapshot', 'riskAssessment'])
            ->get();

        $buckets = $chainFilter !== null && ChainBucket::isValid($chainFilter)
            ? [$chainFilter]
            : ChainBucket::ALL;

        $data = [];
        foreach ($buckets as $bucket) {
            $rows = $tokens
                ->filter(function (Token $t) use ($bucket, $activeSince, $chainFilter): bool {
                    if ($chainFilter !== null && ! ChainBucket::isValid($chainFilter)) {
                        if (mb_strtolower($t->chain_id) !== $chainFilter) {
                            return false;
                        }
                    } elseif (ChainBucket::forChain($t->chain_id) !== $bucket) {
                        return false;
                    }

                    $s = $t->latestSnapshot;
                    if ($s === null || $s->observed_at === null || $s->observed_at->lessThan($activeSince)) {
                        return false;
                    }

                    return MarketIntegrityGate::passes($s->volume_h24, $s->liquidity_usd, $s->market_cap, $s->txns_h24);
                })
                ->sortByDesc(fn (Token $t): float => (float) ($t->latestSnapshot?->volume_h24 ?? 0.0))
                ->take($perChain)
                ->map(fn (Token $t): array => $this->row($t, $now, $staleHours))
                ->values()
                ->all();

            $data[] = [
                'chain_bucket' => $bucket,
                'label' => ChainBucket::label($bucket),
                'tokens' => $rows,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'chain' => $chainFilter,
                'per_chain' => $perChain,
                'active_within_hours' => $activeHours,
                'retrieved_at' => $now->toIso8601String(),
                'source' => 'postgresql',
                'note' => 'Reported 24h volume (one figure per token). The market-integrity gate removes obvious anomalies; it does not certify the volume as organic.',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(Token $token, CarbonImmutable $now, int $staleHours): array
    {
        $s = $token->latestSnapshot;
        $assessment = $token->riskAssessment;
        $checkedAt = $assessment?->screened_at;

        return [
            'token_id' => $token->id,
            'chain_id' => $token->chain_id,
            'token_address' => $token->token_address,
            'symbol' => $token->symbol,
            'name' => $token->name,
            'reported_volume_usd' => $s?->volume_h24,
            'liquidity_usd' => $s?->liquidity_usd,
            'market_cap' => $s?->market_cap,
            'transaction_count' => $s?->txns_h24,
            'risk_level' => $assessment?->risk_level,
            'risk_checked_at' => $checkedAt?->toIso8601String(),
            'risk_check_stale' => $checkedAt === null || $checkedAt->lessThan($now->subHours($staleHours)),
            'observed_at' => $s?->observed_at?->toIso8601String(),
        ];
    }
}
