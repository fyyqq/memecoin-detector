<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * Persists one {@see EvidenceCandidate} for a {@see PumpEvent}, idempotently.
 *
 * Keyed on `(pump_event_id, dedupe_hash)` so re-running the collection command
 * refreshes the existing row instead of inserting a duplicate. The caller reads
 * `wasRecentlyCreated` on the returned model to tell "new" from "refreshed".
 */
class EvidenceRecorder
{
    public function record(PumpEvent $event, Token $token, EvidenceCandidate $candidate): Evidence
    {
        return Evidence::query()->updateOrCreate(
            [
                'pump_event_id' => $event->id,
                'dedupe_hash' => $candidate->dedupeHash(),
            ],
            [
                ...$candidate->toAttributes(),
                'token_id' => $token->id,
                'collected_at' => CarbonImmutable::now(),
            ],
        );
    }
}
