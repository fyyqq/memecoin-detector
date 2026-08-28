<?php

declare(strict_types=1);

namespace App\DTOs\DexScreener;

use Carbon\CarbonImmutable;

/**
 * A token that passed Sprint 1 eligibility: age <= 30 days AND
 * observed_peak_market_cap >= threshold.
 *
 * Combines the current observation ({@see TokenCandidateData}) with the peak
 * figures persisted on the Token record.
 */
final readonly class QualifiedCandidate
{
    public function __construct(
        public TokenCandidateData $current,
        public ?float $observedPeakMarketCap,
        public ?CarbonImmutable $observedPeakMarketCapAt,
        public CarbonImmutable $observedSince,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $c = $this->current;

        return [
            'token_key' => $c->tokenKey,
            'chain_id' => $c->chainId,
            'token_address' => $c->tokenAddress,
            'name' => $c->name,
            'symbol' => $c->symbol,

            'current_market_cap' => $c->marketCap,
            'observed_peak_market_cap' => $this->observedPeakMarketCap,
            'observed_peak_market_cap_at' => $this->observedPeakMarketCapAt?->toIso8601String(),
            'observed_since' => $this->observedSince->toIso8601String(),

            'fdv' => $c->fdv,
            'liquidity_usd' => $c->liquidityUsd,
            'volume_h24' => $c->volumeH24,
            'price_usd' => $c->priceUsd,
            'price_change_h24' => $c->priceChangeH24,
            'txns_h24' => $c->txnsH24,
            'buys_h24' => $c->buysH24,
            'sells_h24' => $c->sellsH24,

            'primary_pair_address' => $c->primaryPairAddress,
            'primary_dex_id' => $c->primaryDexId,
            'pair_count' => $c->pairCount,

            'earliest_pair_created_at' => $c->earliestPairCreatedAt?->toIso8601String(),
            'age_days' => $c->ageDays,

            'size_basis' => $c->sizeBasis,
            'sources' => $c->sources,
            'data_source' => $c->dataSource,
            'retrieved_at' => $c->retrievedAt->toIso8601String(),
        ];
    }
}
