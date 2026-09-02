<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Maintains `tokens.recently_crossed_qualified_at` — the persisted "previously
 * approved by Recently Crossed" marker that feeds the "📈 Post-30-Day Memecoins"
 * continuation list.
 *
 * PostgreSQL-only. Reuses exactly the same DB-level filters
 * (Token::scopeRecentlyCrossedListingCandidate), representative-crossing window
 * check, and deterministic gates ({@see RecentlyCrossedQualifier}) as
 * RecentlyCrossedController — it never calls DexScreener / CoinGecko /
 * GeckoTerminal / GoPlus, and it is the ONLY writer of this column.
 *
 * Two passes:
 *   1. STAMP — a newly-qualifying token is stamped once. Among several
 *      ticker/name-squatting contracts ({@see SameTickerCollapser}) only the
 *      saner record is stamped.
 *   2. REVOKE — a stamped token that now trips a HARD red flag (momentum
 *      anomaly / post-crossing collapse / unscreenable chain) has its stamp
 *      cleared and the reason recorded. A SOFT miss (gentle cool below $5M,
 *      stale discovery, a covered-chain HIGH/CRITICAL rescreen) KEEPS the stamp
 *      — the token's Post-30-Day lineage survives.
 *
 * Revocation is limited to tokens whose crossing is still inside the 30-day
 * window: a genuine Post-30-Day resident whose crossing has aged out keeps its
 * permanent historical approval.
 */
class RecentlyCrossedApprovalMarker
{
    public function __construct(private readonly RecentlyCrossedQualifier $qualifier) {}

    /**
     * @return array{
     *     candidates:int, newly_marked:int, revoked:int,
     *     marked_tokens:list<array<string,mixed>>, revoked_tokens:list<array<string,mixed>>
     * }
     */
    public function mark(?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subDays((int) config('dexscreener.recent_crossing.window_days', 30));

        $stamp = $this->stampNewlyQualified($now, $windowStart, $dryRun);
        $revoke = $this->revokeRedFlagged($now, $windowStart, $dryRun);

        return [
            'candidates' => $stamp['candidates'],
            'newly_marked' => count($stamp['tokens']),
            'revoked' => count($revoke),
            'marked_tokens' => $stamp['tokens'],
            'revoked_tokens' => $revoke,
        ];
    }

    /**
     * @return array{candidates:int,tokens:list<array<string,mixed>>}
     */
    private function stampNewlyQualified(CarbonImmutable $now, CarbonImmutable $windowStart, bool $dryRun): array
    {
        $candidates = Token::query()
            ->recentlyCrossedListingCandidate($now)
            ->whereNull('recently_crossed_qualified_at')
            ->whereNull('recently_crossed_revoked_at')
            ->withCount('marketSnapshots')
            ->with(['latestSnapshot', 'qualificationEvents', 'riskAssessment.signals'])
            ->get();

        /** @var Collection<int, Token> $passing */
        $passing = $candidates->filter(function (Token $token) use ($now, $windowStart): bool {
            $event = $token->representativeQualificationEvent();
            if ($event === null || $event->crossed_at === null) {
                return false;
            }

            // The REPRESENTATIVE crossing must itself be inside the window.
            if ($event->crossed_at->getTimestamp() < $windowStart->getTimestamp()) {
                return false;
            }

            return $this->qualifier->evaluate($token, $now)->qualifies;
        });

        $tokens = [];
        foreach (SameTickerCollapser::winners($passing) as $token) {
            if (! $dryRun) {
                $token->forceFill(['recently_crossed_qualified_at' => $now])->save();
            }
            $tokens[] = ['id' => $token->id, 'chain_id' => $token->chain_id, 'symbol' => $token->symbol];
        }

        return ['candidates' => $candidates->count(), 'tokens' => $tokens];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function revokeRedFlagged(CarbonImmutable $now, CarbonImmutable $windowStart, bool $dryRun): array
    {
        $stamped = Token::query()
            ->whereNotNull('recently_crossed_qualified_at')
            ->whereNull('recently_crossed_revoked_at')
            ->whereHas('qualificationEvents', fn ($query) => $query->where('crossed_at', '>=', $windowStart))
            ->with(['latestSnapshot', 'qualificationEvents', 'riskAssessment.signals'])
            ->get();

        $revoked = [];
        foreach ($stamped as $token) {
            $reason = $this->qualifier->redFlag($token, $now);
            if ($reason === null) {
                continue;
            }

            if (! $dryRun) {
                $token->forceFill([
                    'recently_crossed_qualified_at' => null,
                    'recently_crossed_revoked_at' => $now,
                    'recently_crossed_revoked_reason' => $reason,
                ])->save();

                Log::warning('Recently-crossed approval revoked', [
                    'token_id' => $token->id,
                    'chain_id' => $token->chain_id,
                    'symbol' => $token->symbol,
                    'reason' => $reason,
                ]);
            }

            $revoked[] = [
                'id' => $token->id,
                'chain_id' => $token->chain_id,
                'symbol' => $token->symbol,
                'reason' => $reason,
            ];
        }

        return $revoked;
    }
}
