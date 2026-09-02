<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Historical\RecentlyCrossedApprovalMarker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-30-Day Memecoin Tracking — maintain the "previously approved by
 * Recently Crossed" marker.
 *
 * All logic lives in {@see RecentlyCrossedApprovalMarker}. Scheduled on the
 * discovery cadence, offset AFTER `memecoins:screen-risk` (so the risk gate
 * sees fresh data) and BEFORE the evidence offset. PostgreSQL-only — no external
 * calls, no discovery. It STAMPS a newly-qualifying token once and REVOKES a
 * stamp when the token now trips a HARD red flag. Read APIs never invoke it.
 */
class MarkRecentlyCrossed extends Command
{
    protected $signature = 'memecoins:mark-recently-crossed {--dry-run : Report what would be stamped / revoked without writing}';

    protected $description = 'Stamp tokens that pass the full "Recently Crossed $5M" gates for Post-30-Day tracking; revoke stamps that now trip a red flag';

    public function handle(RecentlyCrossedApprovalMarker $marker): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $marker->mark(dryRun: $dryRun);
        } catch (Throwable $e) {
            $this->error('Recently-crossed approval marking failed: '.$e->getMessage());
            Log::error('Recently-crossed approval marking failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Recently-crossed approval marking — DRY RUN (no writes).'
            : 'Recently-crossed approval marking completed.');
        $this->line('Unmarked candidates evaluated: '.$result['candidates']);
        $this->line(($dryRun ? 'Would mark (approve):          ' : 'Newly marked (approved):       ').$result['newly_marked']);
        $this->line(($dryRun ? 'Would revoke:                  ' : 'Revoked (red flag):            ').$result['revoked']);

        foreach ($result['marked_tokens'] as $t) {
            $this->line(sprintf('  + %s / %s (id %d)', $t['chain_id'], $t['symbol'] ?? '?', $t['id']));
        }
        foreach ($result['revoked_tokens'] as $t) {
            $this->line(sprintf('  - %s / %s (id %d) — %s', $t['chain_id'], $t['symbol'] ?? '?', $t['id'], $t['reason']));
        }

        return self::SUCCESS;
    }
}
