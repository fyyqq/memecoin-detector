<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\PumpEvent;
use Carbon\CarbonImmutable;

/**
 * The bounded time window a collector may investigate for one PumpEvent:
 *
 *   investigation_start = event.started_at - window.before_minutes
 *   investigation_end   = event.peak_at    + window.after_minutes
 *
 * Collectors must never look outside this window.
 */
final readonly class EvidenceWindow
{
    public function __construct(
        public CarbonImmutable $eventStart,
        public CarbonImmutable $eventPeak,
        public CarbonImmutable $investigationStart,
        public CarbonImmutable $investigationEnd,
    ) {}

    public static function for(PumpEvent $event): self
    {
        $before = (int) config('evidence.window.before_minutes', 60);
        $after = (int) config('evidence.window.after_minutes', 30);

        /** @var CarbonImmutable $start */
        $start = $event->started_at;
        /** @var CarbonImmutable $peak */
        $peak = $event->peak_at;

        return new self(
            eventStart: $start,
            eventPeak: $peak,
            investigationStart: $start->subMinutes($before),
            investigationEnd: $peak->addMinutes($after),
        );
    }

    public function contains(?CarbonImmutable $at): bool
    {
        return $at !== null
            && $at->greaterThanOrEqualTo($this->investigationStart)
            && $at->lessThanOrEqualTo($this->investigationEnd);
    }

    /**
     * Neutral phrasing of an item's timing relative to the observed peak.
     * Never implies causality.
     */
    public function relativeToPeak(CarbonImmutable $at): string
    {
        $minutes = (int) round(abs($at->diffInMinutes($this->eventPeak, false)));

        if ($at->lessThan($this->eventPeak)) {
            return "{$minutes} minutes before the observed pump peak";
        }
        if ($at->greaterThan($this->eventPeak)) {
            return "{$minutes} minutes after the observed pump peak";
        }

        return 'at the observed pump peak';
    }
}
