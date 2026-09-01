<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MonthlyRanking;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthlyChampionService;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Monthly Top Memecoins" finalization (Step 22, corrected).
 *
 * With NO arguments it does the safe daily pass: refresh every chain bucket of
 * the current `provisional` month and settle every not-yet-settled bucket of the
 * previous completed month (so on September 1 it finalizes AUGUST — all five
 * buckets — never September).
 *
 * `--year=` / `--month=` settle one specific month's five buckets; `--chain=`
 * restricts to one bucket. The command refuses to finalize a month that is not
 * yet complete unless `--force` is also given. `--force` recomputes settled
 * rows too.
 *
 * Deterministic + internal only — it never calls a provider or web search
 * (that is `memecoins:research-monthly-champions`). The GET API never recomputes.
 */
class FinalizeMonthlyChampion extends Command
{
    protected $signature = 'memecoins:finalize-monthly-champion
        {--year= : Calendar year to finalize (requires --month)}
        {--month= : Calendar month 1-12 to finalize (requires --year)}
        {--chain= : Restrict to one chain bucket (solana|robinhood|bsc|base|other)}
        {--force : Recompute even a settled month / an incomplete month}';

    protected $description = 'Compute + finalize Monthly Top Memecoins per chain bucket (default: the previous completed month + refresh the current provisional month)';

    public function handle(MonthlyChampionService $service): int
    {
        $now = CarbonImmutable::now();
        $year = $this->option('year');
        $month = $this->option('month');
        $chain = $this->option('chain');
        $force = (bool) $this->option('force');

        if ($chain !== null && ! ChainBucket::isValid((string) $chain)) {
            $this->error('--chain must be one of: '.implode(', ', ChainBucket::ALL));

            return self::INVALID;
        }

        try {
            if ($year !== null || $month !== null) {
                if ($year === null || $month === null) {
                    $this->error('--year and --month must be given together.');

                    return self::INVALID;
                }
                $rows = $service->finalizeMonth((int) $year, (int) $month, $force, $now, $chain !== null ? (string) $chain : null);
                $this->renderMonth((int) $year, (int) $month, $rows);

                return self::SUCCESS;
            }

            $previous = MonthWindow::containing($now)->previous();
            $this->line(sprintf(
                'Daily pass — refresh %s (5 buckets, provisional), settle %s %d and earlier (5 buckets each).',
                MonthWindow::containing($now)->monthName(),
                $previous->monthName(),
                $previous->year,
            ));

            $result = $service->refresh($now);

            $this->info('Monthly top-memecoins pass completed.');
            $this->newLine();
            $this->line('Finalized:               '.$result->finalized);
            $this->line('Provisional:             '.$result->provisional);
            $this->line('Best-supported candidate:'.$result->bestSupportedCandidate);
            $this->line('No verified champion:    '.$result->noVerifiedChampion);
            $this->line('Skipped (settled):       '.$result->skippedSettled);
            $this->line('Duration (s):            '.$result->durationSeconds);
        } catch (Throwable $e) {
            $this->error('Monthly top-memecoins finalization failed: '.$e->getMessage());
            Log::error('Monthly top-memecoins finalization failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, MonthlyRanking>  $rows
     */
    private function renderMonth(int $year, int $month, Collection $rows): void
    {
        $window = MonthWindow::of($year, $month);
        $this->info(sprintf('%s %d', $window->monthName(), $year));
        $this->newLine();

        foreach ($rows as $ranking) {
            $ranking->loadMissing('token');
            $label = str_pad(ucfirst($ranking->chain_bucket).':', 12);

            if ($ranking->token_id === null) {
                $this->line("  {$label} {$ranking->status}");

                continue;
            }

            $this->line(sprintf(
                '  %s %s  %s  +%d%% growth  peak %s  (%s / %s / %s)',
                $label,
                $ranking->token?->symbol ?? '#'.$ranking->token_id,
                $ranking->status,
                round((float) $ranking->market_cap_growth_pct),
                $this->money($ranking->peak_market_cap),
                $ranking->source_type ?? '—',
                $ranking->confidence ?? '—',
                round((float) $ranking->observation_coverage_ratio * 100).'% coverage',
            ));
        }
    }

    private function money(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return '$'.number_format($value / 1_000_000, 1).'M';
    }
}
