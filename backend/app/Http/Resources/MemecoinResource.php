<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HistoricalPeakEvidence;
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
 * `latestSnapshot`); the `qualification_*` fields come from the token's
 * {@see HistoricalPeakEvidence} (eager-loaded as `historicalPeakEvidence`), or
 * are derived as CURRENT_OBSERVATION from `observed_peak_market_cap` when no
 * evidence row exists yet.
 *
 * Every row in this list qualifies on a VERIFIED / OBSERVED market cap:
 * `qualification_status` is always CURRENT_OBSERVATION or HISTORICAL_VERIFIED,
 * `qualification_peak_value` is always a real market cap (never an FDV estimate).
 * `observed_peak_market_cap` (OUR OWN snapshot peak) and `qualification_peak_value`
 * are deliberately reported as separate fields.
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
        $qualification = $this->qualification();

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

            // How this token qualifies for the 30-day universe.
            'qualification_status' => $qualification['status'],
            'qualification_peak_value' => $qualification['peak_value'],
            'qualification_peak_at' => $qualification['peak_at'],
            'qualification_source' => $qualification['source'],
            'qualification_basis' => $qualification['basis'],

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
     * The qualifying evidence for this row — always a VERIFIED / OBSERVED market
     * cap (the list query only returns CURRENT_OBSERVATION and HISTORICAL_VERIFIED
     * tokens). Prefers the stored {@see HistoricalPeakEvidence}; falls back to
     * the mirrored Token columns.
     *
     * @return array{status:?string,peak_value:?float,peak_at:?string,source:?string,basis:?string}
     */
    private function qualification(): array
    {
        /** @var HistoricalPeakEvidence|null $evidence */
        $evidence = $this->historicalPeakEvidence;

        $threshold = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');

        if ($evidence !== null && $evidence->qualifies($threshold)) {
            return [
                'status' => $evidence->status,
                'peak_value' => $evidence->peak_value_usd,
                'peak_at' => $evidence->peak_observed_at?->toIso8601String(),
                'source' => $evidence->evidence_source,
                'basis' => $evidence->evidence_basis,
            ];
        }

        if ($this->observed_peak_market_cap !== null && $this->observed_peak_market_cap >= $threshold) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
                'peak_value' => $this->observed_peak_market_cap,
                'peak_at' => $this->observed_peak_market_cap_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                'basis' => HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP,
            ];
        }

        // Defensive: derive HISTORICAL_VERIFIED from the mirrored columns when the
        // evidence relation is not loaded. `historical_peak_value` only ever
        // holds a verified/observed market cap.
        if ($this->historical_peak_status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED
            && $this->historical_peak_value !== null
            && $this->historical_peak_value >= $threshold) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
                'peak_value' => $this->historical_peak_value,
                'peak_at' => $this->historical_peak_value_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_COINGECKO,
                'basis' => HistoricalPeakEvidence::BASIS_MARKET_CAP,
            ];
        }

        return ['status' => null, 'peak_value' => null, 'peak_at' => null, 'source' => null, 'basis' => null];
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
