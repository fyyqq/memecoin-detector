<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Services\DexScreener\DexScreenerClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a single token's DexScreener pairs (`/token-pairs/v1`) for liquidity
 * structure (pool / DEX spread). Reuses the existing {@see DexScreenerClient}
 * (which already caches responses) and adds a per-run call budget.
 *
 * Only the `memecoins:screen-risk` command calls this — never a read API.
 */
class DexScreenerLiquidityProbe
{
    private bool $enabled;

    private int $maxCallsPerRun;

    private float $dominantShare;

    private int $callsMade = 0;

    public function __construct(private readonly DexScreenerClient $client)
    {
        $this->enabled = (bool) config('risk.dexscreener.enabled', true);
        $this->maxCallsPerRun = (int) config('risk.dexscreener.max_calls_per_run', 40);
        $this->dominantShare = (float) config('risk.liquidity.dominant_pool_share', 0.90);
    }

    public function resetBudget(): void
    {
        $this->callsMade = 0;
    }

    public function callsMade(): int
    {
        return $this->callsMade;
    }

    public function structure(string $chainId, string $tokenAddress): LiquidityStructure
    {
        if (! $this->enabled || $this->callsMade >= $this->maxCallsPerRun) {
            return LiquidityStructure::unavailable();
        }
        $this->callsMade++;

        try {
            $byToken = $this->client->tokenPairsBatch([
                ['chain_id' => $chainId, 'token_address' => $tokenAddress],
            ]);
        } catch (Throwable $e) {
            Log::warning('Risk liquidity probe failed', ['chain' => $chainId, 'error' => $e->getMessage()]);

            return LiquidityStructure::unavailable();
        }

        $key = mb_strtolower($chainId).':'.mb_strtolower($tokenAddress);
        $pairs = $byToken[$key] ?? [];

        if ($pairs === []) {
            return LiquidityStructure::unavailable();
        }

        return LiquidityStructure::fromPairs($pairs, $this->dominantShare);
    }
}
