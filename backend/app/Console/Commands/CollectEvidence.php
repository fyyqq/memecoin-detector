<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Evidence;
use App\Services\Evidence\EvidenceCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects timestamped FACTS around recent detected pump events — it does NOT
 * explain them (AI explanation is Step 16C).
 *
 * Safe to run repeatedly: a per-event cooldown and the recorder's dedupe key
 * keep the 10-minute scheduler cadence from piling up duplicates. `--force`
 * re-investigates every recent event regardless of cooldown.
 *
 * Scheduled a few minutes AFTER `memecoins:detect-pumps` (see routes/console.php)
 * so it always sees freshly detected events.
 */
class CollectEvidence extends Command
{
    protected $signature = 'memecoins:collect-evidence {--force : Re-investigate recent events even if within the cooldown}';

    protected $description = 'Collect evidence (market, metadata, related-token, news) around recent pump events';

    public function handle(EvidenceCollectionService $service): int
    {
        try {
            $result = $service->collect((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('Evidence collection failed: '.$e->getMessage());
            Log::error('Evidence collection failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $labels = [
            Evidence::CATEGORY_NEWS => 'News evidence',
            Evidence::CATEGORY_MARKET => 'Market evidence',
            Evidence::CATEGORY_RELATED_TOKEN => 'Related-token evidence',
            Evidence::CATEGORY_ORIGIN => 'Origin evidence',
            Evidence::CATEGORY_TOKEN_METADATA => 'Token-metadata evidence',
        ];

        $this->info('Evidence collection completed.');
        $this->newLine();
        $this->line('Pump events analyzed:       '.$result->eventsAnalyzed);
        $this->line('Events skipped (cooldown):  '.$result->eventsSkippedByCooldown);
        $this->line('Events with new evidence:   '.$result->eventsWithNewEvidence);

        foreach (EvidenceCollectionService::reportableCategories() as $category) {
            $this->line(str_pad(($labels[$category] ?? $category).':', 27).$result->categoryCount($category));
        }

        $this->line('New evidence records:       '.$result->newEvidenceRecords);
        $this->line('Total evidence records:     '.$result->totalEvidenceRecords);
        $this->line('Provider failures:          '.$result->providerFailures);
        $this->line('Duration (s):               '.$result->durationSeconds);

        return self::SUCCESS;
    }
}
