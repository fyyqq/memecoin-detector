<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MonthlyRanking;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthlyChampionResearchRunResult;
use App\Services\Ranking\MonthlyChampionResearchService;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Historical Monthly Champion Backfill (Step 25).
 *
 * For a PAST completed month + chain bucket, researches external / historical
 * market sources (operator-verified, via the seed file) to identify the
 * best-supported #1 performing memecoin — instead of returning "no champion"
 * just because our detector did not exist yet.
 *
 * It NEVER claims an exact DexScreener historical rank unless a source
 * establishes it, NEVER invents a candidate / URL / date, and NEVER scrapes
 * search-engine result pages. Incomplete evidence => `best_supported_candidate`
 * or `no_verified_champion`. Not scheduled — run on demand, one month
 * (five buckets) or one bucket at a time.
 */
class ResearchMonthlyChampions extends Command
{
    protected $signature = 'memecoins:research-monthly-champions
        {--year= : Calendar year (required)}
        {--month= : Calendar month 1-12 (required)}
        {--chain= : Restrict to one chain bucket (solana|robinhood|bsc|base|other)}
        {--force : Re-research even a finalized bucket / the current month}';

    protected $description = 'Historically backfill Monthly Top Memecoins per chain bucket from researched market evidence';

    public function handle(MonthlyChampionResearchService $service): int
    {
        $year = $this->option('year');
        $month = $this->option('month');
        $chain = $this->option('chain');

        if ($year === null || $month === null) {
            $this->error('--year and --month are both required.');

            return self::INVALID;
        }
        if ((int) $month < 1 || (int) $month > 12) {
            $this->error('--month must be 1-12.');

            return self::INVALID;
        }
        if ($chain !== null && ! ChainBucket::isValid((string) $chain)) {
            $this->error('--chain must be one of: '.implode(', ', ChainBucket::ALL));

            return self::INVALID;
        }

        $window = MonthWindow::of((int) $year, (int) $month);
        $this->info(sprintf('%s %d', $window->monthName(), (int) $year));
        $this->newLine();

        try {
            $result = $service->research(
                (int) $year,
                (int) $month,
                $chain !== null ? (string) $chain : null,
                (bool) $this->option('force'),
                CarbonImmutable::now(),
                progress: function (string $bucket, string $phase, ?MonthlyRanking $row): void {
                    $label = str_pad(ChainBucket::isValid($bucket) ? ucfirst($bucket) : $bucket, 10);
                    if ($phase === 'researching') {
                        $this->line("  {$label} → researching");
                    } elseif ($phase === 'skipped') {
                        $this->line("  {$label} → already finalized (use --force)");
                    } elseif ($phase === 'done' && $row !== null) {
                        $this->line(sprintf(
                            '  %s → %s%s',
                            $label,
                            $this->describe($row),
                            $row->age_uncertain ? '  [age_uncertain]' : '',
                        ));
                    }
                },
            );
        } catch (Throwable $e) {
            $this->error('Monthly champion research failed: '.$e->getMessage());
            Log::error('Monthly champion research failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    private function describe(MonthlyRanking $row): string
    {
        if ($row->token_id === null && $row->champion_symbol === null) {
            return $row->status;
        }
        $row->loadMissing('token');
        $symbol = $row->champion_symbol ?? $row->token?->symbol ?? '#'.$row->token_id;

        return sprintf(
            '%s — #%d %s (%s / %s / score %s / holders %s / vol %s / mc %s)',
            $row->status,
            (int) $row->rank,
            $symbol,
            $row->source_type ?? '—',
            $row->confidence ?? '—',
            $row->performance_score !== null ? round((float) $row->performance_score, 1) : '?',
            $row->holder_count !== null ? number_format((int) $row->holder_count) : 'UNKNOWN',
            $row->monthly_volume_usd !== null ? '$'.number_format((float) $row->monthly_volume_usd / 1_000_000, 1).'M' : 'UNKNOWN',
            $row->month_market_cap !== null ? '$'.number_format((float) $row->month_market_cap / 1_000_000, 1).'M' : 'UNKNOWN',
        );
    }

    private function render(MonthlyChampionResearchRunResult $result): void
    {
        $this->newLine();
        $this->info('Historical research completed.');
        $this->line('Providers used:            '.(implode(', ', $result->providersUsed) ?: 'none'));
        $this->line('Buckets finalized:         '.$result->finalized);
        $this->line('No verified result:       '.$result->noVerifiedChampion);
        $this->line('Future (untouched):        '.$result->future);
        $this->line('Skipped (finalized):       '.$result->skipped);
        $this->line('Ranked rows written:       '.count($result->buckets));
        $this->line('Provider failures:         '.$result->providerFailures);
    }
}
