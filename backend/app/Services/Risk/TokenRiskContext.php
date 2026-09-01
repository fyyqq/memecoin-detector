<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything {@see RiskSignalEvaluator} needs for one token — assembled by
 * {@see RiskScreeningService} from PostgreSQL + the (already fetched) provider
 * lookups. The evaluator itself makes no queries and no HTTP calls.
 */
final class TokenRiskContext
{
    /**
     * @param  Collection<int, MarketSnapshot>  $snapshots
     * @param  Collection<int, PumpEvent>  $pumpEvents
     */
    public function __construct(
        public readonly Token $token,
        public readonly ?MarketSnapshot $latestSnapshot,
        public readonly Collection $snapshots,
        public readonly Collection $pumpEvents,
        public readonly GoPlusSecurityLookup $goplus,
        public readonly GeckoTerminalInfoLookup $geckoterminal,
        public readonly LiquidityStructure $liquidity,
        public readonly ChartShape $chartShape,
        public readonly HolderConcentration $holders,
        public readonly CarbonImmutable $now,
    ) {}

    public function ageHours(): ?float
    {
        $created = $this->token->earliest_pair_created_at;
        if ($created === null) {
            return null;
        }

        return max(0.0, ($this->now->getTimestamp() - $created->getTimestamp()) / 3600.0);
    }

    public function currentMarketCap(): ?float
    {
        return $this->latestSnapshot?->market_cap;
    }
}
