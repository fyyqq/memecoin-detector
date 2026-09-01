<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyChainActivity;
use App\Models\DailyTrendingRanking;
use App\Models\TrendingSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prunes trending history by the configured retention windows.
 *
 *   trending_snapshots      older than MEMECOIN_TREND_SNAPSHOT_RETENTION_DAYS (30)
 *   daily_trending_rankings  older than MEMECOIN_DAILY_TREND_RETENTION_DAYS   (365)
 *   daily_chain_activity     older than MEMECOIN_DAILY_TREND_RETENTION_DAYS   (365)
 *
 * Scheduled daily. NEVER run on an API request. The 5-minute snapshots are the
 * large table; the daily rollups are small and kept long.
 */
class CleanupTrending extends Command
{
    protected $signature = 'memecoins:cleanup-trending
        {--days= : Override snapshot retention (days)}
        {--daily-days= : Override daily-rollup retention (days)}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune trending snapshots + daily rollups past their retention windows';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $snapshotDays = (int) ($this->option('days') ?: config('trending.retention.snapshot_days', 30));
        $dailyDays = (int) ($this->option('daily-days') ?: config('trending.retention.daily_days', 365));
        $dryRun = (bool) $this->option('dry-run');

        $snapshotCutoff = $now->subDays(max(1, $snapshotDays));
        $dailyCutoff = $now->subDays(max(1, $dailyDays))->toDateString();

        $snapshotQuery = TrendingSnapshot::query()->where('captured_at', '<', $snapshotCutoff);
        $rankingQuery = DailyTrendingRanking::query()->where('date', '<', $dailyCutoff);
        $activityQuery = DailyChainActivity::query()->where('date', '<', $dailyCutoff);

        $snapshotCount = (clone $snapshotQuery)->count();
        $rankingCount = (clone $rankingQuery)->count();
        $activityCount = (clone $activityQuery)->count();

        if (! $dryRun) {
            $snapshotQuery->delete();
            $rankingQuery->delete();
            $activityQuery->delete();
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info('Trending cleanup completed'.($dryRun ? ' (dry run)' : '').'.');
        $this->newLine();
        $this->line($verb.' trending_snapshots:      '.$snapshotCount.'  (older than '.$snapshotCutoff->toDateString().')');
        $this->line($verb.' daily_trending_rankings: '.$rankingCount.'  (older than '.$dailyCutoff.')');
        $this->line($verb.' daily_chain_activity:    '.$activityCount.'  (older than '.$dailyCutoff.')');

        return self::SUCCESS;
    }
}
