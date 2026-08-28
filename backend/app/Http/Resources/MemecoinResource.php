<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketSnapshot;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the "30-Day Leaders" dashboard.
 *
 * Identity + peak state come from the {@see Token}; the current market fields
 * come from its **latest** {@see MarketSnapshot} (eager-loaded as
 * `latestSnapshot`).
 *
 * @mixin Token
 */
class MemecoinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->latestSnapshot;

        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'token_address' => $this->token_address,
            'name' => $this->name,
            'symbol' => $this->symbol,

            // Current market state — from the latest observation, not the Token.
            'current_market_cap' => $snapshot?->market_cap,
            'observed_peak_market_cap' => $this->observed_peak_market_cap,
            'observed_peak_market_cap_at' => $this->observed_peak_market_cap_at?->toIso8601String(),

            'age_days' => $this->ageDays(),

            'liquidity_usd' => $snapshot?->liquidity_usd,
            'volume_h24' => $snapshot?->volume_h24,

            'primary_dex_id' => $snapshot?->primary_dex_id,
            'primary_pair_address' => $snapshot?->primary_pair_address,

            'data_source' => 'dexscreener',
            'last_observed_at' => $this->last_observed_at?->toIso8601String(),
        ];
    }

    /**
     * Age from earliest DEX pool creation to now (days, 2dp). NOT token deploy
     * time. Null when we never captured a pool-creation timestamp.
     */
    private function ageDays(): ?float
    {
        if ($this->earliest_pair_created_at === null) {
            return null;
        }

        $seconds = CarbonImmutable::now()->getTimestamp() - $this->earliest_pair_created_at->getTimestamp();

        return round($seconds / 86_400, 2);
    }
}
