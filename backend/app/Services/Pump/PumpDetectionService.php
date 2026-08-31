<?php

declare(strict_types=1);

namespace App\Services\Pump;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic pump-event detection over the STORED observation series.
 *
 * Pipeline: DexScreener → snapshots → (this). It reads only what already exists —
 * **never calls DexScreener, CoinGecko or GeckoTerminal**, never fetches.
 *
 *   eligible tokens (recently observed, enough snapshots)
 *   → per token: PumpDetector → ?PumpDetection
 *   → PumpEventRecorder (create or merge)
 *   → sweep stale active events → completed
 */
class PumpDetectionService
{
    private int $recentTokenMinutes;

    private int $recentSnapshotsPerToken;

    private int $minimumSnapshots;

    private int $staleAfterMinutes;

    public function __construct(
        private readonly PumpDetector $detector,
        private readonly PumpEventRecorder $recorder,
    ) {
        $windowMinutes = (int) config('pump.windows.primary_minutes') + (int) config('pump.windows.tolerance_minutes');
        $this->recentTokenMinutes = $windowMinutes + (int) config('pump.query.recent_token_minutes');
        $this->recentSnapshotsPerToken = max(2, (int) config('pump.query.recent_snapshots_per_token'));
        $this->minimumSnapshots = max(2, (int) config('pump.query.minimum_snapshots'));
        $this->staleAfterMinutes = (int) config('pump.event_stale_after_minutes');
    }

    public function detect(?CarbonImmutable $now = null): PumpDetectionResult
    {
        $now ??= CarbonImmutable::now();

        /** @var list<int> $tokenIds */
        $tokenIds = Token::query()
            ->whereNotNull('last_observed_at')
            ->where('last_observed_at', '>=', $now->subMinutes($this->recentTokenMinutes))
            ->pluck('id')
            ->all();

        $snapshotsByToken = $this->recentSnapshots($tokenIds);
        /** @var Collection<int,Token> $tokens */
        $tokens = Token::query()->whereIn('id', $tokenIds)->get()->keyBy('id');

        $analyzed = 0;
        $created = 0;
        $updated = 0;

        foreach ($snapshotsByToken as $tokenId => $rows) {
            /** @var Token|null $token */
            $token = $tokens->get($tokenId);
            if ($token === null) {
                continue;
            }

            /** @var list<MarketSnapshot> $ordered */
            $ordered = $rows->sortBy([['observed_at', 'asc'], ['id', 'asc']])->values()->all();
            if (count($ordered) < $this->minimumSnapshots) {
                continue;
            }

            $analyzed++;

            $detection = $this->detector->detect($ordered);
            if ($detection === null) {
                continue;
            }

            $outcome = $this->recorder->record($token, $detection);
            $outcome['action'] === 'created' ? $created++ : $updated++;
        }

        $sweptCompleted = $this->completeStaleEvents($now);

        return new PumpDetectionResult(
            tokensAnalyzed: $analyzed,
            eventsCreated: $created,
            eventsUpdated: $updated,
            eventsCompletedBySweep: $sweptCompleted,
            activeEvents: PumpEvent::query()->where('status', PumpEvent::STATUS_ACTIVE)->count(),
            completedEvents: PumpEvent::query()->where('status', PumpEvent::STATUS_COMPLETED)->count(),
        );
    }

    /**
     * The most recent N snapshots per token, in ONE query (window function).
     * Never loads a token's full history.
     *
     * @param  list<int>  $tokenIds
     * @return Collection<int,Collection<int,MarketSnapshot>>
     */
    private function recentSnapshots(array $tokenIds): Collection
    {
        if ($tokenIds === []) {
            return collect();
        }

        $ranked = DB::table('market_snapshots')
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY token_id ORDER BY observed_at DESC, id DESC) AS rn')
            ->whereIn('token_id', $tokenIds);

        return MarketSnapshot::query()
            ->joinSub($ranked, 'ranked', fn ($join) => $join->on('ranked.id', '=', 'market_snapshots.id'))
            ->where('ranked.rn', '<=', $this->recentSnapshotsPerToken)
            ->get()
            ->groupBy('token_id');
    }

    /**
     * Any `active` event whose peak observation is older than
     * `event_stale_after_minutes` and which is no longer being detected → mark
     * `completed`. Backstop for tokens that stopped being observed.
     */
    private function completeStaleEvents(CarbonImmutable $now): int
    {
        $cutoff = $now->subMinutes($this->staleAfterMinutes);

        /** @var Collection<int,PumpEvent> $stale */
        $stale = PumpEvent::query()
            ->where('status', PumpEvent::STATUS_ACTIVE)
            ->where('peak_at', '<', $cutoff)
            ->with('token:id,last_observed_at')
            ->get();

        foreach ($stale as $event) {
            $lastObserved = $event->token?->last_observed_at;
            $event->status = PumpEvent::STATUS_COMPLETED;
            $event->ended_at = ($lastObserved !== null && $lastObserved->greaterThan($event->peak_at))
                ? $lastObserved
                : $event->peak_at;
            $event->save();
        }

        return $stale->count();
    }
}
