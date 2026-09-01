<?php

declare(strict_types=1);

namespace App\Services\Ranking\Providers;

use App\Services\Ranking\MonthlyChampionResearchProvider;
use App\Services\Ranking\MonthlyResearchCandidate;
use App\Services\Ranking\MonthlyResearchContext;
use Illuminate\Support\Facades\Log;

/**
 * Automated external web-research provider for past monthly chain champions.
 *
 * There is NO official documented DexScreener API endpoint for a historical
 * monthly Trending leaderboard, and search-engine result pages must not be
 * scraped. Automated internet research that resolves a small memecoin's
 * identity + $5M–$200M MARKET CAP + ≤ 30-day trading age for a specific past
 * month is not reliably possible from any free API. So this provider is a
 * documented extension point that is **OFF by default** and returns `[]` — the
 * actual historical research flows through the curated
 * {@see SeedFileMonthlyResearchProvider} (operator-verified sources).
 */
class WebMonthlyResearchProvider implements MonthlyChampionResearchProvider
{
    private bool $lastCallFailed = false;

    public function name(): string
    {
        return 'web_research';
    }

    public function isAvailable(): bool
    {
        return (bool) config('ranking.research.web.enabled', false)
            && config('ranking.research.web.base_url') !== null;
    }

    public function lastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    /**
     * @return list<MonthlyResearchCandidate>
     */
    public function research(MonthlyResearchContext $context): array
    {
        Log::info('WebMonthlyResearchProvider is a stub; no automated historical trending source is configured.', [
            'year' => $context->year(), 'month' => $context->month(), 'bucket' => $context->bucket,
        ]);

        return [];
    }
}
