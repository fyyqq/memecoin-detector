<?php

declare(strict_types=1);

namespace App\Services\Pump;

use App\Models\PumpEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists a {@see PumpDetection} as a {@see PumpEvent}, MERGING it into the
 * token's most recent event when the two overlap within
 * `event_merge_window_minutes` — so one continuous pump is a single row and
 * older events are never overwritten.
 */
class PumpEventRecorder
{
    private int $mergeWindowMinutes;

    public function __construct(private readonly PumpSignalCalculator $calc)
    {
        $this->mergeWindowMinutes = (int) config('pump.event_merge_window_minutes');
    }

    /**
     * @return array{action: 'created'|'merged', event: PumpEvent}
     */
    public function record(Token $token, PumpDetection $detection): array
    {
        // Serialize per token so two concurrent detection runs (e.g. the
        // scheduled job overlapping a manual one) can't both create an event
        // for the same continuous pump — the second waits, then merges.
        return DB::transaction(function () use ($token, $detection): array {
            $recent = PumpEvent::query()
                ->where('token_id', $token->id)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($recent !== null && $this->overlaps($recent, $detection)) {
                $this->merge($recent, $detection);

                return ['action' => 'merged', 'event' => $recent];
            }

            /** @var PumpEvent $event */
            $event = PumpEvent::query()->create(['token_id' => $token->id] + $detection->toAttributes());

            return ['action' => 'created', 'event' => $event];
        });
    }

    private function overlaps(PumpEvent $event, PumpDetection $d): bool
    {
        /** @var CarbonImmutable $eventEnd */
        $eventEnd = ($event->ended_at ?? $event->peak_at)->addMinutes($this->mergeWindowMinutes);

        return $event->started_at->lessThanOrEqualTo($d->peakAt)
            && $d->startedAt->lessThanOrEqualTo($eventEnd);
    }

    private function merge(PumpEvent $event, PumpDetection $d): void
    {
        // Earliest start wins (keep the true trough).
        if ($d->startedAt->lessThan($event->started_at)) {
            $event->started_at = $d->startedAt;
            $event->start_market_cap = $d->startMarketCap;
            $event->start_price_usd = $d->startPriceUsd;
        }

        // Highest peak wins (by market cap, fallback price).
        $existingPeak = $event->peak_market_cap ?? $event->peak_price_usd ?? 0.0;
        $newPeak = $d->peakMarketCap ?? $d->peakPriceUsd ?? 0.0;
        if ($newPeak > $existingPeak) {
            $event->peak_at = $d->peakAt;
            $event->peak_market_cap = $d->peakMarketCap;
            $event->peak_price_usd = $d->peakPriceUsd;
        }

        // Recompute the headline move from the merged start → peak.
        $event->market_cap_change_pct = $this->calc->pct($event->start_market_cap, $event->peak_market_cap);
        $event->price_change_pct = $this->calc->pct($event->start_price_usd, $event->peak_price_usd);

        // Activity ratios: keep the freshest reading.
        $event->volume_h24_change_ratio = $d->volumeH24ChangeRatio ?? $event->volume_h24_change_ratio;
        $event->txns_h24_change_ratio = $d->txnsH24ChangeRatio ?? $event->txns_h24_change_ratio;

        $event->duration_minutes = (int) round($event->started_at->diffInMinutes($event->peak_at));

        // A pump doesn't get weaker on merge.
        $event->detection_score = max($event->detection_score, $d->detectionScore);
        $event->confidence = $this->higherConfidence($event->confidence, $d->confidence);

        if ($d->status === PumpEvent::STATUS_ACTIVE) {
            $event->status = PumpEvent::STATUS_ACTIVE;
            $event->ended_at = null;
        } elseif ($d->endedAt !== null && $d->endedAt->greaterThan($event->peak_at)) {
            $event->status = PumpEvent::STATUS_COMPLETED;
            $event->ended_at = $d->endedAt;
        }

        $event->save();
    }

    private function higherConfidence(string $a, string $b): string
    {
        return PumpEvent::CONFIDENCE_RANK[$a] >= PumpEvent::CONFIDENCE_RANK[$b] ? $a : $b;
    }
}
