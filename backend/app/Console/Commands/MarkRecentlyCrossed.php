<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Historical\RecentlyCrossedApprovalMarker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-30-Day Memecoin Tracking — stamp the "previously approved by
 * Recently Crossed" marker.
 *
 * All logic lives in {@see RecentlyCrossedApprovalMarker}. Scheduled on the
 * discovery cadence, offset AFTER `memecoins:screen-risk` (so the risk gate
 * sees fresh data) and BEFORE the evidence offset. PostgreSQL-only — no external
 * calls, no discovery. It only ever WRITES `tokens.recently_crossed_qualified_at`
 * (once per token, never cleared). Read APIs never invoke it.
 */
class MarkRecentlyCrossed extends Command
{
    protected $signature = 'memecoins:mark-recently-crossed';

    protected $description = 'Stamp tokens that currently pass the full "Recently Crossed $5M" gates so they continue into Post-30-Day tracking';

    public function handle(RecentlyCrossedApprovalMarker $marker): int
    {
        try {
            $result = $marker->mark();
        } catch (Throwable $e) {
            $this->error('Recently-crossed approval marking failed: '.$e->getMessage());
            Log::error('Recently-crossed approval marking failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('Recently-crossed approval marking completed.');
        $this->line('Unmarked candidates evaluated: '.$result['candidates']);
        $this->line('Newly marked (approved):       '.$result['newly_marked']);

        return self::SUCCESS;
    }
}
