<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Risk\GeckoTerminalInfoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "Monthly Top Memecoins" holder pass (Step 25).
 *
 * For the CURRENT provisional month, polls GeckoTerminal `/info` for the eligible
 * candidate tokens and returns a monthly-MAX holder count per token. There is no
 * `market_snapshots` change and no holder capture in the 10-minute discovery
 * loop — this runs once a day inside `memecoins:finalize-monthly-champion`.
 *
 *   - reuses {@see GeckoTerminalInfoClient} (the same adapter the risk screen
 *     uses; free, no key, never throws);
 *   - a per-run token cap (`ranking.holder_pass.max_tokens_per_run`) and a
 *     per-token cooldown (`ranking.holder_pass.cooldown_hours`, read from the
 *     prior stored `monthly_rankings.holder_checked_at`) keep the provider load
 *     tiny;
 *   - the returned count is `max(prior stored, fresh)` — the monthly maximum,
 *     carried forward across daily runs on the ranking rows themselves;
 *   - GeckoTerminal returning nothing → `holderCount` stays `null` (UNKNOWN),
 *     which drops holder strength from the score (the weights renormalize).
 *     Never a fabricated count.
 *
 * Past months: no live GeckoTerminal history exists for a completed month, so a
 * finalized past bucket gets a holder count ONLY from an operator seed row.
 */
class MonthlyHolderCollector
{
    public function __construct(private readonly GeckoTerminalInfoClient $gecko) {}

    /**
     * @param  Collection<int, Token>  $eligibleTokens
     * @return array<int, MonthlyHolderObservation> keyed by token id
     */
    public function collect(int $year, int $month, Collection $eligibleTokens, CarbonImmutable $now): array
    {
        if (! (bool) config('ranking.holder_pass.enabled', true) || $eligibleTokens->isEmpty()) {
            return [];
        }

        $maxTokens = max(0, (int) config('ranking.holder_pass.max_tokens_per_run', 25));
        $cooldownHours = max(0, (int) config('ranking.holder_pass.cooldown_hours', 20));
        $cooldownCutoff = $now->subHours($cooldownHours);

        // Prior state per token for THIS calendar month (one query).
        /** @var array<int, array{holder_count:?int,holder_checked_at:?string}> $prior */
        $prior = MonthlyRanking::query()
            ->where('year', $year)
            ->where('month', $month)
            ->whereNotNull('token_id')
            ->get(['token_id', 'holder_count', 'holder_checked_at'])
            ->keyBy('token_id')
            ->map(fn (MonthlyRanking $r): array => [
                'holder_count' => $r->holder_count,
                'holder_checked_at' => $r->holder_checked_at?->toIso8601String(),
            ])
            ->all();

        // Never-checked tokens first, then oldest check.
        $ordered = $eligibleTokens->sortBy(function (Token $t) use ($prior): string {
            $at = $prior[$t->id]['holder_checked_at'] ?? '0000';

            return $at.':'.str_pad((string) $t->id, 12, '0', STR_PAD_LEFT);
        })->values();

        $this->gecko->resetBudget();
        $fetched = 0;
        $out = [];

        foreach ($ordered as $token) {
            $priorCount = $prior[$token->id]['holder_count'] ?? null;
            $priorCheckedAt = isset($prior[$token->id]['holder_checked_at'])
                ? CarbonImmutable::parse($prior[$token->id]['holder_checked_at'])
                : null;

            $withinCooldown = $priorCheckedAt !== null && $priorCheckedAt->greaterThan($cooldownCutoff);

            if ($withinCooldown || $fetched >= $maxTokens) {
                $out[$token->id] = new MonthlyHolderObservation($priorCount, $priorCheckedAt);

                continue;
            }

            $fresh = null;
            try {
                $fresh = $this->gecko->info((string) $token->chain_id, (string) $token->token_address)->holderCount();
            } catch (Throwable $e) {
                Log::warning('Monthly holder pass: GeckoTerminal /info threw', ['token' => $token->id, 'error' => $e->getMessage()]);
            }
            $fetched++;

            $monthlyMax = match (true) {
                $fresh !== null && $priorCount !== null => max($fresh, $priorCount),
                $fresh !== null => $fresh,
                default => $priorCount,
            };

            $out[$token->id] = new MonthlyHolderObservation($monthlyMax, $now);
        }

        return $out;
    }
}
