<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\TokenCandidateData;
use Carbon\CarbonImmutable;

/**
 * Turns the raw `/token-pairs/v1` pair list for one token into a single
 * {@see TokenCandidateData}.
 *
 * Pure and deterministic: all time input is injected. No HTTP, no filtering
 * decisions (those live in {@see DexScreenerDiscoveryService}).
 */
class DexScreenerNormalizer
{
    private const MS_PER_DAY = 86_400_000;

    /**
     * @param  list<array<string,mixed>>  $pairs  Raw pair objects for this token.
     * @param  list<string>  $sources  Discovery sources that surfaced the token.
     */
    public function normalize(
        string $chainId,
        string $tokenAddress,
        array $pairs,
        array $sources,
        ?CarbonImmutable $now = null,
    ): ?TokenCandidateData {
        $now ??= CarbonImmutable::now();

        $ownPairs = $this->pairsForToken($pairs, $chainId, $tokenAddress);

        if ($ownPairs === []) {
            return null;
        }

        $primary = $this->representativePair($ownPairs);

        $base = is_array($primary['baseToken'] ?? null) ? $primary['baseToken'] : [];

        $volume = is_array($primary['volume'] ?? null) ? $primary['volume'] : [];
        $priceChange = is_array($primary['priceChange'] ?? null) ? $primary['priceChange'] : [];
        $liquidity = is_array($primary['liquidity'] ?? null) ? $primary['liquidity'] : [];
        $txns24 = is_array($primary['txns'] ?? null) && is_array($primary['txns']['h24'] ?? null)
            ? $primary['txns']['h24']
            : [];

        $buys = $this->intOrNull($txns24['buys'] ?? null);
        $sells = $this->intOrNull($txns24['sells'] ?? null);
        $txnsTotal = ($buys === null && $sells === null) ? null : (int) ($buys ?? 0) + (int) ($sells ?? 0);

        $earliestMs = $this->earliestPairCreatedAtMs($ownPairs);
        $earliestAt = $earliestMs !== null
            ? CarbonImmutable::createFromTimestampMs($earliestMs)
            : null;
        $ageDays = $earliestMs !== null
            ? round(($now->getTimestampMs() - $earliestMs) / self::MS_PER_DAY, 4)
            : null;

        $marketCap = $this->floatOrNull($primary['marketCap'] ?? null);
        $fdv = $this->floatOrNull($primary['fdv'] ?? null);

        return new TokenCandidateData(
            tokenKey: $this->tokenKey($chainId, $tokenAddress),
            chainId: $chainId,
            tokenAddress: $tokenAddress,
            name: $this->stringOrNull($base['name'] ?? null),
            symbol: $this->stringOrNull($base['symbol'] ?? null),
            primaryPairAddress: $this->stringOrNull($primary['pairAddress'] ?? null),
            primaryDexId: $this->stringOrNull($primary['dexId'] ?? null),
            priceUsd: $this->floatOrNull($primary['priceUsd'] ?? null),
            marketCap: $marketCap,
            fdv: $fdv,
            liquidityUsd: $this->floatOrNull($liquidity['usd'] ?? null),
            volumeH24: $this->floatOrNull($volume['h24'] ?? null),
            priceChangeH24: $this->floatOrNull($priceChange['h24'] ?? null),
            txnsH24: $txnsTotal,
            buysH24: $buys,
            sellsH24: $sells,
            pairCount: count($ownPairs),
            earliestPairCreatedAt: $earliestAt,
            ageDays: $ageDays,
            sources: array_values(array_unique($sources)),
            retrievedAt: $now,
            sizeBasis: $this->sizeBasis($marketCap, $fdv),
        );
    }

    public function tokenKey(string $chainId, string $tokenAddress): string
    {
        return mb_strtolower(trim($chainId)).':'.mb_strtolower(trim($tokenAddress));
    }

    /**
     * Keep only pairs whose base token is the token we asked about. The endpoint
     * is already chain+token scoped, but quote-side matches and stray rows are
     * filtered defensively.
     *
     * @param  list<array<string,mixed>>  $pairs
     * @return list<array<string,mixed>>
     */
    private function pairsForToken(array $pairs, string $chainId, string $tokenAddress): array
    {
        $chain = mb_strtolower(trim($chainId));
        $token = mb_strtolower(trim($tokenAddress));

        return array_values(array_filter($pairs, function ($pair) use ($chain, $token): bool {
            if (! is_array($pair)) {
                return false;
            }

            $pairChain = mb_strtolower((string) ($pair['chainId'] ?? ''));
            $baseAddress = is_array($pair['baseToken'] ?? null)
                ? mb_strtolower((string) ($pair['baseToken']['address'] ?? ''))
                : '';

            return $pairChain === $chain && $baseAddress === $token;
        }));
    }

    /**
     * Representative pair = highest `liquidity.usd`. When no pair reports
     * liquidity, fall back deterministically to the lexicographically smallest
     * `pairAddress` so the choice is stable across calls.
     *
     * @param  list<array<string,mixed>>  $pairs  Non-empty.
     * @return array<string,mixed>
     */
    private function representativePair(array $pairs): array
    {
        $withLiquidity = array_filter(
            $pairs,
            fn ($pair): bool => $this->floatOrNull(($pair['liquidity']['usd'] ?? null)) !== null,
        );

        if ($withLiquidity !== []) {
            usort(
                $withLiquidity,
                fn ($a, $b): int => ($this->floatOrNull($b['liquidity']['usd']) ?? 0.0)
                    <=> ($this->floatOrNull($a['liquidity']['usd']) ?? 0.0),
            );

            return $withLiquidity[0];
        }

        $sorted = $pairs;
        usort(
            $sorted,
            fn ($a, $b): int => strcmp(
                (string) ($a['pairAddress'] ?? ''),
                (string) ($b['pairAddress'] ?? ''),
            ),
        );

        return $sorted[0];
    }

    /**
     * Smallest non-null `pairCreatedAt` (Unix ms) across the token's pairs.
     *
     * @param  list<array<string,mixed>>  $pairs
     */
    private function earliestPairCreatedAtMs(array $pairs): ?int
    {
        $timestamps = [];

        foreach ($pairs as $pair) {
            $value = $this->intOrNull($pair['pairCreatedAt'] ?? null);

            if ($value !== null && $value > 0) {
                $timestamps[] = $value;
            }
        }

        return $timestamps === [] ? null : min($timestamps);
    }

    private function sizeBasis(?float $marketCap, ?float $fdv): string
    {
        if ($marketCap !== null) {
            return 'market_cap';
        }

        if ($fdv !== null) {
            return 'fdv';
        }

        return 'unknown';
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
