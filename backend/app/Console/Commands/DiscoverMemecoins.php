<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IngestionRun;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the existing discovery / persistence pipeline once and reports a concise
 * summary — to the console (manual runs) and to the log (so the scheduler
 * container surfaces it in `docker compose logs scheduler`).
 *
 * All business logic lives in {@see DexScreenerDiscoveryService} — this command
 * only invokes it. Invoked every 10 minutes by the scheduler
 * (see routes/console.php); also runnable by hand for debugging.
 */
class DiscoverMemecoins extends Command
{
    protected $signature = 'memecoins:discover
        {--trigger=scheduled : Ingestion trigger to record (scheduled|manual)}
        {--chain= : Optional chain_id filter}';

    protected $description = 'Discover memecoins from DexScreener and persist market observations';

    public function handle(DexScreenerDiscoveryService $discovery): int
    {
        $trigger = $this->option('trigger') === IngestionRun::TRIGGER_MANUAL
            ? IngestionRun::TRIGGER_MANUAL
            : IngestionRun::TRIGGER_SCHEDULED;

        $chain = $this->option('chain') ?: null;

        try {
            $result = $discovery->discover($chain, null, $trigger);
        } catch (Throwable $e) {
            $this->error('Memecoin discovery failed: '.$e->getMessage());
            Log::error('Memecoin discovery failed', ['trigger' => $trigger, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $d = $result->diagnostics;

        $summary = [
            'ingestion_run' => $result->ingestionRunId,
            'trigger' => $trigger,
            'raw_candidates' => $d['raw_discovery_candidates'],
            'unique_candidates' => $d['unique_candidates'],
            'enriched' => $d['enriched_ok'],
            'age_eligible' => $d['age_eligible'],
            'snapshots_written' => $d['snapshots_written'],
            'new_tokens' => $d['new_tokens'],
            'peak_updated' => $d['peak_updated'],
            'qualified' => $d['qualified'],
        ];

        Log::info('Memecoin discovery completed', $summary);

        $this->info('Memecoin discovery completed.');
        $this->newLine();
        $this->line('Ingestion run:      #'.$result->ingestionRunId.' ('.$trigger.')');
        $this->line('Raw candidates:     '.$d['raw_discovery_candidates']);
        $this->line('Unique candidates:  '.$d['unique_candidates']);
        $this->line('Enriched:           '.$d['enriched_ok']);
        $this->line('Age eligible:       '.$d['age_eligible']);
        $this->line('Snapshots written:  '.$d['snapshots_written']);
        $this->line('New tokens:         '.$d['new_tokens']);
        $this->line('Peak updated:       '.$d['peak_updated']);
        $this->line('Qualified:          '.$d['qualified']);

        return self::SUCCESS;
    }
}
