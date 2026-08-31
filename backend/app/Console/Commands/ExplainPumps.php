<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\PumpExplanationRunResult;
use App\Services\AI\PumpExplanationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates evidence-grounded AI explanations for recent pump events.
 *
 * The LLM only ever interprets Evidence records our database already collected —
 * it never adds facts. All logic lives in {@see PumpExplanationService}.
 *
 * Scheduled a few minutes AFTER `memecoins:collect-evidence` (see
 * routes/console.php). `--force` regenerates recent explanations ignoring the
 * cooldown.
 */
class ExplainPumps extends Command
{
    protected $signature = 'memecoins:explain-pumps {--force : Regenerate recent explanations even if within the cooldown}';

    protected $description = 'Generate evidence-backed AI explanations for recent pump events';

    public function handle(PumpExplanationService $service): int
    {
        try {
            $result = $service->explain((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('Pump explanation run failed: '.$e->getMessage());
            Log::error('Pump explanation run failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    private function render(PumpExplanationRunResult $result): void
    {
        $this->info('Pump explanation completed.');
        $this->newLine();
        $this->line('Events analyzed:         '.$result->eventsAnalyzed);
        $this->line('Explanations generated:  '.$result->explanationsGenerated);
        $this->line('Skipped:                 '.$result->skipped());
        $this->line('  · cooldown:            '.$result->skippedCooldown);
        $this->line('  · no evidence:         '.$result->skippedNoEvidence);
        $this->line('Failed:                  '.$result->failed);
        $this->line('Duration (s):            '.$result->durationSeconds);
    }
}
