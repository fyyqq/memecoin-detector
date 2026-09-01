<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Trending\TrendingCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Near-real-time trending collection (Trending Tracking).
 *
 * All logic lives in {@see TrendingCollectionService}. Scheduled every
 * ~5 minutes (MEMECOIN_TREND_REFRESH_MINUTES). It fetches the documented
 * DexScreener trending-meta APIs, scores + ranks 6h and 24h, writes
 * `trending_snapshots` + `daily_trending_rankings`, enriches brand-new trending
 * tokens, and recomputes `daily_chain_activity`.
 *
 * It does NOT run risk screening — that stays on its own cooldown. Read APIs
 * never invoke this.
 */
class CollectTrending extends Command
{
    protected $signature = 'memecoins:collect-trending {--force : Reserved — collection is idempotent per 5-minute bucket}';

    protected $description = 'Collect near-real-time DexScreener trending (6h + 24h) into persistent snapshots';

    public function handle(TrendingCollectionService $service): int
    {
        try {
            $result = $service->collect(force: (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('Trending collection failed: '.$e->getMessage());
            Log::error('Trending collection failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('Trending collection completed.');
        $this->newLine();
        $this->line('Capture bucket:      '.$result->captureBucket);
        $this->line('Trending metas:      '.$result->metaCount);
        $this->line('Meta pairs seen:     '.$result->pairsSeen);
        $this->line('Unique tokens:       '.$result->uniqueTokens);
        $this->newLine();
        $this->line('  excluded non-memecoin:   '.$result->excludedNonMemecoin);
        $this->line('  excluded ambiguous:      '.$result->excludedAmbiguousMemecoin);
        $this->line('  excluded current MC:     '.$result->excludedCurrentMarketCap);
        $this->line('  excluded no liquidity:   '.$result->excludedNoLiquidity);
        $this->line('  excluded no volume:      '.$result->excludedNoVolume);
        $this->line('  excluded age unknown:    '.$result->excludedAgeUnknown);
        $this->line('  excluded age > 30d:      '.$result->excludedTooOld);
        $this->line('  ELIGIBLE memecoins:      '.$result->eligibleCandidates);
        $this->newLine();
        foreach ($result->candidatesPerTimeframe as $tf => $n) {
            $this->line('Scored ('.$tf.'):        '.$n);
        }
        $this->line('Snapshots written:   '.$result->snapshotsWritten);
        $this->line('Daily rankings:      '.$result->dailyRankingsUpserted);
        $this->line('New tokens enriched: '.$result->newTokensEnriched.' / '.$result->enrichAttempted);
        $this->line('Chain activity rows: '.$result->chainActivityRowsWritten);
        $this->line('Chains seen:         '.count($result->chainsSeen));
        $this->line('Duration:            '.$result->durationSeconds.'s');

        return self::SUCCESS;
    }
}
