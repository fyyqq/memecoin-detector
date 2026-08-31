<?php

declare(strict_types=1);

namespace App\DTOs\DexScreener;

use App\Models\HistoricalPeakEvidence;
use Carbon\CarbonImmutable;

/**
 * A token that passed Sprint 1 eligibility: age <= 30 days AND a VERIFIED /
 * OBSERVED market cap has reached the threshold — via CURRENT_OBSERVATION or
 * HISTORICAL_VERIFIED. HISTORICAL_ESTIMATE (FDV basis) does NOT qualify.
 *
 * Combines the current observation ({@see TokenCandidateData}), OUR OWN observed
 * peak (from the Token record), and the historical-qualification determination
 * ({@see HistoricalPeakEvidence}). `observed_peak_market_cap` and
 * the `qualification_*` figures are kept explicitly distinct.
 */
final readonly class QualifiedCandidate
{
    public function __construct(
        public TokenCandidateData $current,
        public ?float $observedPeakMarketCap,
        public ?CarbonImmutable $observedPeakMarketCapAt,
        public CarbonImmutable $observedSince,
        public string $qualificationStatus,
        public ?float $qualificationPeakValue,
        public ?CarbonImmutable $qualificationPeakAt,
        public ?string $qualificationSource,
        public ?string $qualificationBasis,
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

            // Historical qualification — NOT the same as observed_peak_market_cap.
            'qualification_status' => $this->qualificationStatus,
            'qualification_peak_value' => $this->qualificationPeakValue,
            'qualification_peak_at' => $this->qualificationPeakAt?->toIso8601String(),
            'qualification_source' => $this->qualificationSource,
            'qualification_basis' => $this->qualificationBasis,

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
            'discovery_context' => $c->discoveryContext,
            'data_source' => $c->dataSource,
            'retrieved_at' => $c->retrievedAt->toIso8601String(),
        ];
    }
}
