<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TokenNarrativeReport;
use App\Services\Narrative\NarrativeResearchRunResult;
use App\Services\Narrative\NarrativeResearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Token Narrative Intelligence (Step 21).
 *
 * Finds notable tokens needing narrative research, collects sources (origin +
 * popularity) from every available provider, ranks them, asks the configured AI
 * provider for an evidence-grounded synthesis, validates it, and persists a
 * {@see TokenNarrativeReport}. All logic lives in
 * {@see NarrativeResearchService}.
 *
 * Scheduled hourly (`withoutOverlapping(30)`) — much slower and more externally
 * dependent than discovery / pump detection, so it never runs on the 10-minute
 * cadence. `--force` re-researches regardless of the 24h cooldown.
 */
class ResearchNarratives extends Command
{
    protected $signature = 'memecoins:research-narratives
        {--force : Re-research even within the cooldown}
        {--token=* : Restrict to specific tokens (chain:address), repeatable}';

    protected $description = 'Research + synthesise token origin and popularity narratives';

    public function handle(NarrativeResearchService $service): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('token')));

        try {
            $result = $service->research((bool) $this->option('force'), $only);
        } catch (Throwable $e) {
            $this->error('Narrative research run failed: '.$e->getMessage());
            Log::error('Narrative research run failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    private function render(NarrativeResearchRunResult $result): void
    {
        $this->info('Narrative research completed.');
        $this->newLine();
        $this->line('Tokens considered:   '.$result->tokensConsidered);
        $this->line('Completed:            '.$result->completed);
        $this->line('Partial:             '.$result->partial);
        $this->line('Failed:              '.$result->failed);
        $this->line('Skipped (cooldown):  '.$result->skippedCooldown);
        $this->line('Sources recorded:    '.$result->sourcesRecorded);
        $this->line('Provider failures:   '.$result->providerFailures);
        $this->line('Duration (s):        '.$result->durationSeconds);
    }
}
