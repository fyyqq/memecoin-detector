<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * A conservative market-integrity gate — removes obvious anomalies BEFORE a
 * token is ranked by reported volume ("Top 5 Volume by Chain", "Chain Market
 * Activity").
 *
 * It does NOT certify the survivor's volume as organic / real human volume — no
 * free provider gives us that. It only excludes:
 *   - zero / missing liquidity
 *   - zero / missing transactions
 *   - impossible / garbage market-cap records
 *   - an extreme volume-to-liquidity ratio (a wash-trade shape)
 *
 * Deterministic + config-driven (config/trending.php `integrity.*`).
 */
final class MarketIntegrityGate
{
    /**
     * @return array{ok:bool,reason:?string}
     */
    public static function check(
        ?float $volumeUsd,
        ?float $liquidityUsd,
        ?float $marketCap,
        ?int $transactionCount,
    ): array {
        $minLiquidity = (float) config('trending.integrity.min_liquidity_usd', 1.0);
        $minTxns = (int) config('trending.integrity.min_transaction_count', 1);
        $maxMc = (float) config('trending.integrity.max_market_cap_usd', 1_000_000_000_000.0);
        $maxRatio = (float) config('trending.integrity.max_volume_liquidity_ratio', 75.0);

        if ($liquidityUsd === null || $liquidityUsd < $minLiquidity) {
            return ['ok' => false, 'reason' => 'no_liquidity'];
        }

        if ($transactionCount === null || $transactionCount < $minTxns) {
            return ['ok' => false, 'reason' => 'no_transactions'];
        }

        if ($marketCap !== null && ($marketCap <= 0.0 || $marketCap > $maxMc)) {
            return ['ok' => false, 'reason' => 'impossible_market_cap'];
        }

        if ($volumeUsd !== null && $volumeUsd > 0.0 && $liquidityUsd > 0.0
            && ($volumeUsd / $liquidityUsd) > $maxRatio) {
            return ['ok' => false, 'reason' => 'extreme_volume_liquidity_ratio'];
        }

        return ['ok' => true, 'reason' => null];
    }

    public static function passes(
        ?float $volumeUsd,
        ?float $liquidityUsd,
        ?float $marketCap,
        ?int $transactionCount,
    ): bool {
        return self::check($volumeUsd, $liquidityUsd, $marketCap, $transactionCount)['ok'];
    }
}
