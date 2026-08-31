<?php

declare(strict_types=1);

namespace App\Services\Historical;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GeckoTerminal adapter — the HISTORICAL_ESTIMATE source.
 *
 * Official public API (no key). Retrieves historical PRICE (OHLCV) and the
 * token's total supply + mint-authority signal, then computes a conservative
 * FDV-basis estimate ONLY when the supply-safety gate passes.
 *
 * Every failure returns a non-`estimate` {@see GeckoTerminalLookup}; never
 * throws. Bounded by `max_calls_per_run`; responses cached for `cache_ttl`.
 *
 * Pool-selection heuristic: the token's pool with the highest current
 * `reserve_in_usd` (deterministic tie-break: lexicographically smallest pool
 * address). This is the token's deepest single market — it is NOT claimed to
 * represent every DEX market for the token.
 */
class GeckoTerminalClient
{
    private bool $enabled;

    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $retrySleepMs;

    private int $cacheTtl;

    private int $maxCallsPerRun;

    private bool $allowWithoutMintSignal;

    private int $callsMade = 0;

    public function __construct()
    {
        $cfg = config('historical.geckoterminal');
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->timeout = (int) ($cfg['timeout'] ?? 8);
        $this->connectTimeout = (int) ($cfg['connect_timeout'] ?? 4);
        $this->retrySleepMs = (int) ($cfg['retry_sleep_ms'] ?? 1_000);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? 21_600);
        $this->maxCallsPerRun = (int) ($cfg['max_calls_per_run'] ?? 45);
        $this->allowWithoutMintSignal = (bool) ($cfg['estimate']['allow_without_mint_signal'] ?? false);
    }

    /**
     * Compute an FDV-basis historical peak estimate for a token.
     *
     * @param  string  $network  GeckoTerminal network id (e.g. "eth", "solana")
     */
    public function historicalEstimate(
        string $network,
        string $tokenAddress,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): GeckoTerminalLookup {
        if (! $this->enabled) {
            return GeckoTerminalLookup::unavailable('geckoterminal: disabled');
        }

        // 1. best pool ------------------------------------------------------
        $poolsBody = $this->getJson(
            "gt:pools:{$network}:".mb_strtolower($tokenAddress),
            "/networks/{$network}/tokens/".rawurlencode($tokenAddress).'/pools',
        );
        $pool = $this->pickBestPool(is_array($poolsBody) ? ($poolsBody['data'] ?? []) : []);

        if ($pool === null) {
            return new GeckoTerminalLookup('no_pool', note: 'geckoterminal: no pool for token');
        }
        $poolAddress = $pool['address'];

        // 2. hourly OHLCV within the window (<=30d => one page of <=720 candles)
        $ohlcvBody = $this->getJson(
            "gt:ohlcv:{$network}:{$poolAddress}:".$windowStart->getTimestamp(),
            "/networks/{$network}/pools/".rawurlencode($poolAddress).'/ohlcv/hour',
            ['limit' => 1000, 'currency' => 'usd', 'before_timestamp' => $windowEnd->getTimestamp()],
        );

        [$peakPrice, $peakAt, $seenStart] = $this->peakPrice(
            is_array($ohlcvBody) ? data_get($ohlcvBody, 'data.attributes.ohlcv_list', []) : [],
            $windowStart,
        );

        if ($peakPrice === null || $peakPrice <= 0.0) {
            return new GeckoTerminalLookup('no_price', $poolAddress, note: 'geckoterminal: no usable OHLCV history');
        }

        // 3. total supply + mint-authority signal -------------------------
        $tokenBody = $this->getJson(
            "gt:token:{$network}:".mb_strtolower($tokenAddress),
            "/networks/{$network}/tokens/".rawurlencode($tokenAddress),
        );
        $totalSupply = $this->normalizedTotalSupply(is_array($tokenBody) ? ($tokenBody['data']['attributes'] ?? []) : []);

        if ($totalSupply === null || $totalSupply <= 0.0) {
            return new GeckoTerminalLookup(
                'supply_missing',
                $poolAddress,
                peakPriceUsd: $peakPrice,
                peakAt: $peakAt,
                windowStart: $seenStart,
                windowEnd: $windowEnd,
                note: 'geckoterminal: no defensible total supply',
            );
        }

        $infoBody = $this->getJson(
            "gt:info:{$network}:".mb_strtolower($tokenAddress),
            "/networks/{$network}/tokens/".rawurlencode($tokenAddress).'/info',
        );
        $mintSignal = $this->mintImmutabilitySignal(is_array($infoBody) ? ($infoBody['data']['attributes'] ?? []) : []);

        // 4. supply-safety gate ------------------------------------------
        //  confirmed_immutable  -> estimate, medium confidence
        //  mutable              -> reject (supply_unsafe)
        //  unknown              -> estimate only if operator opted in (low), else reject
        if ($mintSignal === 'mutable') {
            return new GeckoTerminalLookup(
                'supply_unsafe',
                $poolAddress,
                peakPriceUsd: $peakPrice,
                totalSupply: $totalSupply,
                peakAt: $peakAt,
                windowStart: $seenStart,
                windowEnd: $windowEnd,
                note: 'geckoterminal: mint authority present — supply is mutable',
            );
        }

        $confidence = null;
        if ($mintSignal === 'confirmed_immutable') {
            $confidence = 'medium';
        } elseif ($this->allowWithoutMintSignal) {
            $confidence = 'low';
        } else {
            return new GeckoTerminalLookup(
                'supply_unsafe',
                $poolAddress,
                peakPriceUsd: $peakPrice,
                totalSupply: $totalSupply,
                peakAt: $peakAt,
                windowStart: $seenStart,
                windowEnd: $windowEnd,
                note: 'geckoterminal: no positive supply-immutability signal (conservative reject)',
            );
        }

        $estimate = $peakPrice * $totalSupply;

        return new GeckoTerminalLookup(
            'estimate',
            $poolAddress,
            peakPriceUsd: $peakPrice,
            totalSupply: $totalSupply,
            estimateUsd: $estimate,
            peakAt: $peakAt,
            windowStart: $seenStart,
            windowEnd: $windowEnd,
            confidence: $confidence,
            note: sprintf('fdv basis: peak price $%.10g x total supply %.0f', $peakPrice, $totalSupply),
        );
    }

    public function callsMade(): int
    {
        return $this->callsMade;
    }

    /** Reset the per-run HTTP call counter (called once per discovery run). */
    public function resetBudget(): void
    {
        $this->callsMade = 0;
    }

    /**
     * @param  array<mixed>  $pools
     * @return array{address:string}|null
     */
    private function pickBestPool(array $pools): ?array
    {
        $best = null;
        $bestReserve = -1.0;

        foreach ($pools as $pool) {
            $attr = is_array($pool) ? ($pool['attributes'] ?? []) : [];
            $address = is_string($attr['address'] ?? null) ? $attr['address'] : null;
            if ($address === null || $address === '') {
                continue;
            }
            $reserve = is_numeric($attr['reserve_in_usd'] ?? null) ? (float) $attr['reserve_in_usd'] : 0.0;

            if ($reserve > $bestReserve
                || ($reserve === $bestReserve && $best !== null && strcmp($address, $best) < 0)) {
                $bestReserve = $reserve;
                $best = $address;
            }
        }

        return $best !== null ? ['address' => $best] : null;
    }

    /**
     * Highest candle `high` within the window.
     *
     * @param  array<mixed>  $ohlcvList  rows of [ts, open, high, low, close, volume]
     * @return array{0:float|null,1:CarbonImmutable|null,2:CarbonImmutable}
     */
    private function peakPrice(array $ohlcvList, CarbonImmutable $windowStart): array
    {
        $peak = null;
        $peakAt = null;
        $earliestSeen = null;

        foreach ($ohlcvList as $row) {
            if (! is_array($row) || count($row) < 3) {
                continue;
            }
            $ts = is_numeric($row[0] ?? null) ? (int) $row[0] : null;
            $high = is_numeric($row[2] ?? null) ? (float) $row[2] : null;
            if ($ts === null || $high === null) {
                continue;
            }
            $at = CarbonImmutable::createFromTimestamp($ts);
            if ($at->lt($windowStart)) {
                continue;
            }
            if ($earliestSeen === null || $at->lt($earliestSeen)) {
                $earliestSeen = $at;
            }
            if ($peak === null || $high > $peak) {
                $peak = $high;
                $peakAt = $at;
            }
        }

        return [$peak, $peakAt, $earliestSeen ?? $windowStart];
    }

    /**
     * @param  array<string,mixed>  $attr
     */
    private function normalizedTotalSupply(array $attr): ?float
    {
        if (is_numeric($attr['normalized_total_supply'] ?? null)) {
            $v = (float) $attr['normalized_total_supply'];

            return $v > 0.0 ? $v : null;
        }

        if (is_numeric($attr['total_supply'] ?? null)) {
            $raw = (float) $attr['total_supply'];
            $decimals = is_numeric($attr['decimals'] ?? null) ? (int) $attr['decimals'] : 0;
            $v = $decimals > 0 ? $raw / (10 ** $decimals) : $raw;

            return $v > 0.0 ? $v : null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $attr  from /tokens/{addr}/info
     * @return 'confirmed_immutable'|'mutable'|'unknown'
     */
    private function mintImmutabilitySignal(array $attr): string
    {
        if (! array_key_exists('mint_authority', $attr)) {
            return 'unknown';
        }

        $mint = $attr['mint_authority'];

        if ($mint === null) {
            return 'confirmed_immutable';
        }

        if (is_string($mint) && trim($mint) !== '') {
            return 'mutable';
        }

        return 'unknown';
    }

    /**
     * Cached GET. Returns decoded array or null on any failure / budget cap.
     *
     * @param  array<string,mixed>  $query
     * @return array<mixed>|null
     */
    private function getJson(string $cacheKey, string $path, array $query = []): ?array
    {
        /** @var array<mixed>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->callsMade >= $this->maxCallsPerRun) {
            Log::info('GeckoTerminal per-run call budget exhausted', ['path' => $path]);

            return null;
        }

        $this->callsMade++;

        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('GeckoTerminal request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429) {
            Log::warning('GeckoTerminal rate limited (429)', [
                'path' => $path,
                'retry_after' => $response->header('Retry-After'),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('GeckoTerminal non-success status', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return null;
        }

        Cache::put($cacheKey, $decoded, $this->cacheTtl);

        return $decoded;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'memecoin-detector/1.0 (+sprint1-historical)'])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(2, $this->retrySleepMs, function (Throwable $e): bool {
                return $e instanceof RequestException
                    && $e->response !== null
                    && $e->response->status() === 429;
            }, throw: false);
    }
}
