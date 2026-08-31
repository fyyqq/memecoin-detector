<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pump\PumpDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs deterministic pump-event detection once over the stored observation
 * series and prints a concise summary.
 *
 * All logic lives in {@see PumpDetectionService} — this command only invokes it.
 * Scheduled every 10 minutes, offset AFTER `memecoins:discover` so it analyses
 * fresh snapshots (see routes/console.php). Never calls DexScreener.
 */
class DetectPumps extends Command
{
    protected $signature = 'memecoins:detect-pumps';

    protected $description = 'Detect significant observed pump events from stored market snapshots';

    public function handle(PumpDetectionService $service): int
    {
        try {
            $result = $service->detect();
        } catch (Throwable $e) {
            $this->error('Pump detection failed: '.$e->getMessage());
            Log::error('Pump detection failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        Log::info('Pump detection completed', $result->toArray());

        $this->info('Pump detection completed.');
        $this->newLine();
        $this->line('Tokens analyzed:            '.$result->tokensAnalyzed);
        $this->line('Pump events created:        '.$result->eventsCreated);
        $this->line('Pump events updated:        '.$result->eventsUpdated);
        $this->line('Completed by stale sweep:   '.$result->eventsCompletedBySweep);
        $this->line('Active events:              '.$result->activeEvents);
        $this->line('Completed events:           '.$result->completedEvents);

        return self::SUCCESS;
    }
}
