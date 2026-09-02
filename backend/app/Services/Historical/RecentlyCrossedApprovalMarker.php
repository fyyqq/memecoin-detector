<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * Stamps `tokens.recently_crossed_qualified_at` the first time a token satisfies
 * the ENTIRE "🔥 Recently Crossed $5M" predicate — the persisted
 * "previously approved by Recently Crossed" marker that feeds the
 * "📈 Post-30-Day Memecoins" continuation list.
 *
 * PostgreSQL-only. Reuses exactly the same DB-level filters
 * (Token::scopeRecentlyCrossedListingCandidate), representative-crossing window
 * check, and deterministic quality gates (RecentlyCrossedQualifier) as
 * RecentlyCrossedController — it never calls DexScreener / CoinGecko /
 * GeckoTerminal / GoPlus, and it is the ONLY writer of this column.
 *
 * The marker is written ONCE and NEVER cleared or rewritten: a token that later
 * dumps below $5M, goes stale, or is re-screened HIGH/CRITICAL keeps its
 * historical approval lineage.
 */
class RecentlyCrossedApprovalMarker
{
    public function __construct(private readonly RecentlyCrossedQualifier $qualifier) {}

    /**
     * @return array{candidates:int,newly_marked:int}
     */
    public function mark(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subDays((int) config('dexscreener.recent_crossing.window_days', 30));

        $candidates = Token::query()
            ->recentlyCrossedListingCandidate($now)
            ->whereNull('recently_crossed_qualified_at')
            ->with(['latestSnapshot', 'qualificationEvents', 'riskAssessment.signals'])
            ->get();

        $marked = 0;

        foreach ($candidates as $token) {
            $event = $token->representativeQualificationEvent();
            if ($event === null || $event->crossed_at === null) {
                continue;
            }

            // The REPRESENTATIVE crossing must itself be inside the window.
            if ($event->crossed_at->getTimestamp() < $windowStart->getTimestamp()) {
                continue;
            }

            if (! $this->qualifier->evaluate($token, $now)->qualifies) {
                continue;
            }

            $token->forceFill(['recently_crossed_qualified_at' => $now])->save();
            $marked++;
        }

        return ['candidates' => $candidates->count(), 'newly_marked' => $marked];
    }
}
