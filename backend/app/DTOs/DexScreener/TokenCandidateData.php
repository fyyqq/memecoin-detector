<?php

declare(strict_types=1);

namespace App\DTOs\DexScreener;

use App\Services\DexScreener\DexScreenerNormalizer;
use Carbon\CarbonImmutable;

/**
 * A single normalized memecoin candidate.
 *
 * This is a pure value object: no persistence, no DexScreener-specific naming.
 * Built by {@see DexScreenerNormalizer} from the raw
 * `/token-pairs/v1` payload for one token.
 */
final readonly class TokenCandidateData
{
    /**
     * @param  list<string>  $sources  Discovery sources that surfaced this token (e.g. ["profile", "search"]).
     * @param  'market_cap'|'fdv'|'unknown'  $sizeBasis  Which size metric satisfied (or would satisfy) the >= $5M rule.
     */
    public function __construct(
        public string $tokenKey,
        public string $chainId,
        public string $tokenAddress,
        public ?string $name,
        public ?string $symbol,
        public ?string $primaryPairAddress,
        public ?string $primaryDexId,
        public ?float $priceUsd,
        public ?float $marketCap,
        public ?float $fdv,
        public ?float $liquidityUsd,
        public ?float $volumeH24,
        public ?float $priceChangeH24,
        public ?int $txnsH24,
        public ?int $buysH24,
        public ?int $sellsH24,
        public int $pairCount,
        public ?CarbonImmutable $earliestPairCreatedAt,
        public ?float $ageDays,
        public array $sources,
        public CarbonImmutable $retrievedAt,
        public string $sizeBasis,
        public string $dataSource = 'dexscreener',
        public ?TokenLinks $links = null,
    ) {}

    /**
     * Return a copy with the given sources unioned in (order preserved, de-duped).
     *
     * @param  list<string>  $extraSources
     */
    public function withSources(array $extraSources): self
    {
        $merged = array_values(array_unique([...$this->sources, ...$extraSources]));

        return new self(
            $this->tokenKey,
            $this->chainId,
            $this->tokenAddress,
            $this->name,
            $this->symbol,
            $this->primaryPairAddress,
            $this->primaryDexId,
            $this->priceUsd,
            $this->marketCap,
            $this->fdv,
            $this->liquidityUsd,
            $this->volumeH24,
            $this->priceChangeH24,
            $this->txnsH24,
            $this->buysH24,
            $this->sellsH24,
            $this->pairCount,
            $this->earliestPairCreatedAt,
            $this->ageDays,
            $merged,
            $this->retrievedAt,
            $this->sizeBasis,
            $this->dataSource,
            $this->links,
        );
    }

    /**
     * Public API shape. Missing values stay `null` — never coerced to 0.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token_key' => $this->tokenKey,
            'chain_id' => $this->chainId,
            'token_address' => $this->tokenAddress,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'primary_pair_address' => $this->primaryPairAddress,
            'primary_dex_id' => $this->primaryDexId,
            'price_usd' => $this->priceUsd,
            'market_cap' => $this->marketCap,
            'fdv' => $this->fdv,
            'liquidity_usd' => $this->liquidityUsd,
            'volume_h24' => $this->volumeH24,
            'price_change_h24' => $this->priceChangeH24,
            'txns_h24' => $this->txnsH24,
            'buys_h24' => $this->buysH24,
            'sells_h24' => $this->sellsH24,
            'pair_count' => $this->pairCount,
            'earliest_pair_created_at' => $this->earliestPairCreatedAt?->toIso8601String(),
            'age_days' => $this->ageDays,
            'size_basis' => $this->sizeBasis,
            'sources' => $this->sources,
            'data_source' => $this->dataSource,
            'retrieved_at' => $this->retrievedAt->toIso8601String(),
        ];
    }
}
