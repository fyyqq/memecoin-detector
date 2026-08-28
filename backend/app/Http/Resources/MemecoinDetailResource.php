<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketSnapshot;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full token detail for the detail page.
 *
 * Identity + peak state come from the {@see Token}; the current market fields
 * come from its latest {@see MarketSnapshot}; `snapshots` is a bounded,
 * newest-first window of recent observations for the history view.
 *
 * Read-only. "Observed peak" is the highest market cap THIS detector has
 * captured since `first_observed_at` — never a lifetime / all-time high.
 * Missing values are JSON `null`, never coerced to `0`.
 *
 * @mixin Token
 */
class MemecoinDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MarketSnapshot|null $snapshot */
        $snapshot = $this->latestSnapshot;

        /** @var Collection<int, MarketSnapshot> $recent */
        $recent = $this->recentSnapshots;

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

            'price_usd' => $snapshot?->price_usd,
            'fdv' => $snapshot?->fdv,
            'liquidity_usd' => $snapshot?->liquidity_usd,
            'volume_h24' => $snapshot?->volume_h24,
            'price_change_h24' => $snapshot?->price_change_h24,
            'txns_h24' => $snapshot?->txns_h24,
            'buys_h24' => $snapshot?->buys_h24,
            'sells_h24' => $snapshot?->sells_h24,

            'primary_dex_id' => $snapshot?->primary_dex_id,
            'primary_pair_address' => $snapshot?->primary_pair_address,

            // Not captured by Sprint 1 persistence — surfaced as null so the UI
            // shows "Unavailable" rather than a fabricated count.
            'pair_count' => null,

            'earliest_pair_created_at' => $this->earliest_pair_created_at?->toIso8601String(),
            'first_observed_at' => $this->first_observed_at?->toIso8601String(),
            'last_observed_at' => $this->last_observed_at?->toIso8601String(),

            'data_source' => 'dexscreener',

            'snapshots' => $recent->map(fn (MarketSnapshot $row): array => [
                'observed_at' => $row->observed_at?->toIso8601String(),
                'price_usd' => $row->price_usd,
                'market_cap' => $row->market_cap,
                'fdv' => $row->fdv,
                'liquidity_usd' => $row->liquidity_usd,
                'volume_h24' => $row->volume_h24,
                'price_change_h24' => $row->price_change_h24,
                'txns_h24' => $row->txns_h24,
                'buys_h24' => $row->buys_h24,
                'sells_h24' => $row->sells_h24,
            ])->all(),
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
