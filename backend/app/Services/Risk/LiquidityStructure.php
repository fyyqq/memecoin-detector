<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Liquidity-structure summary for a token, derived from its DexScreener pairs.
 *
 * "Multiple pools" is a risk-REDUCTION signal only — it never means "safe".
 * `null` fields mean the data was unavailable (UNKNOWN), never 0.
 */
final class LiquidityStructure
{
    /**
     * @param  list<array{pair_address:?string,dex_id:?string,liquidity_usd:float}>  $pools
     */
    public function __construct(
        public readonly bool $available,
        public readonly int $poolCount,
        public readonly int $dexCount,
        public readonly float $totalLiquidityUsd,
        public readonly float $topPoolLiquidityUsd,
        public readonly ?float $largestPoolShare,
        public readonly bool $singlePool,
        public readonly array $pools = [],
    ) {}

    public static function unavailable(): self
    {
        return new self(false, 0, 0, 0.0, 0.0, null, true, []);
    }

    /**
     * @param  list<array<string,mixed>>  $rawPairs  DexScreener `/token-pairs/v1` list
     */
    public static function fromPairs(array $rawPairs, float $dominantShare): self
    {
        $pools = [];
        foreach ($rawPairs as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $liq = data_get($pair, 'liquidity.usd');
            $liq = is_numeric($liq) ? (float) $liq : 0.0;
            if ($liq <= 0.0) {
                continue;
            }
            $pools[] = [
                'pair_address' => is_string(data_get($pair, 'pairAddress')) ? (string) data_get($pair, 'pairAddress') : null,
                'dex_id' => is_string(data_get($pair, 'dexId')) ? (string) data_get($pair, 'dexId') : null,
                'liquidity_usd' => $liq,
            ];
        }

        if ($pools === []) {
            return new self(true, 0, 0, 0.0, 0.0, null, true, []);
        }

        usort($pools, fn (array $a, array $b): int => $b['liquidity_usd'] <=> $a['liquidity_usd']);

        $total = array_sum(array_column($pools, 'liquidity_usd'));
        $top = $pools[0]['liquidity_usd'];
        $share = $total > 0.0 ? $top / $total : null;
        $dexes = array_values(array_unique(array_filter(array_column($pools, 'dex_id'))));

        return new self(
            available: true,
            poolCount: count($pools),
            dexCount: count($dexes),
            totalLiquidityUsd: $total,
            topPoolLiquidityUsd: $top,
            largestPoolShare: $share,
            singlePool: count($pools) === 1 || ($share !== null && $share >= $dominantShare),
            pools: $pools,
        );
    }

    /** Set of pool contract addresses (lowercased) — for holder-list exclusion. */
    public function poolAddresses(): array
    {
        return array_values(array_filter(array_map(
            fn (array $p): ?string => is_string($p['pair_address'] ?? null) ? mb_strtolower($p['pair_address']) : null,
            $this->pools,
        )));
    }
}
