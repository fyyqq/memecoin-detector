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
 * Cautious CoinGecko adapter — the HISTORICAL_VERIFIED source.
 *
 * Official public API only. Every failure (404, 429, timeout, malformed body,
 * per-run budget exhausted, disabled) returns a typed {@see CoinGeckoLookup}
 * with a non-`verified` outcome — it never throws into the pipeline.
 *
 * Bounded: at most `max_calls_per_run` HTTP calls per instance lifetime;
 * responses cached for `cache_ttl`; 429 is retried once honoring `Retry-After`.
 */
class CoinGeckoClient
{
    private bool $enabled;

    private string $baseUrl;

    private ?string $apiKey;

    private string $apiKeyHeader;

    private int $timeout;

    private int $connectTimeout;

    private int $retrySleepMs;

    private int $cacheTtl;

    private int $maxCallsPerRun;

    private int $callsMade = 0;

    public function __construct()
    {
        $cfg = config('historical.coingecko');
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->apiKey = ($cfg['api_key'] ?? null) ?: null;
        $this->apiKeyHeader = (string) ($cfg['api_key_header'] ?? 'x-cg-demo-api-key');
        $this->timeout = (int) ($cfg['timeout'] ?? 8);
        $this->connectTimeout = (int) ($cfg['connect_timeout'] ?? 4);
        $this->retrySleepMs = (int) ($cfg['retry_sleep_ms'] ?? 1_000);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? 21_600);
        $this->maxCallsPerRun = (int) ($cfg['max_calls_per_run'] ?? 20);
    }

    /**
     * Look up the maximum non-zero historical market cap for a token over
     * [$windowStart, $windowEnd] and decide whether it clears $threshold.
     *
     * @param  string  $platformId  CoinGecko asset-platform id (e.g. "solana")
     */
    public function historicalPeak(
        string $platformId,
        string $tokenAddress,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        float $threshold,
    ): CoinGeckoLookup {
        if (! $this->enabled) {
            return CoinGeckoLookup::unavailable('coingecko: disabled');
        }

        $contract = $this->getJson(
            "coingecko:contract:{$platformId}:".mb_strtolower($tokenAddress),
            '/coins/'.rawurlencode($platformId).'/contract/'.rawurlencode($tokenAddress),
        );

        if ($contract === self::NOT_FOUND) {
            return CoinGeckoLookup::notFound();
        }

        if (! is_array($contract)) {
            return CoinGeckoLookup::unavailable('coingecko: contract lookup unavailable');
        }

        $coinId = is_string($contract['id'] ?? null) ? $contract['id'] : null;

        if ($coinId === null || $coinId === '') {
            return CoinGeckoLookup::unavailable('coingecko: contract response had no coin id');
        }

        $chart = $this->getJson(
            "coingecko:mcchart:{$coinId}:".$windowStart->getTimestamp().'-'.$windowEnd->getTimestamp(),
            '/coins/'.rawurlencode($coinId).'/market_chart/range',
            [
                'vs_currency' => 'usd',
                'from' => $windowStart->getTimestamp(),
                'to' => $windowEnd->getTimestamp(),
            ],
        );

        if (! is_array($chart)) {
            return CoinGeckoLookup::unavailable('coingecko: market_chart unavailable for '.$coinId);
        }

        $points = is_array($chart['market_caps'] ?? null) ? $chart['market_caps'] : [];

        $peak = null;
        $peakAt = null;
        foreach ($points as $point) {
            if (! is_array($point) || count($point) < 2) {
                continue;
            }
            $value = is_numeric($point[1] ?? null) ? (float) $point[1] : 0.0;
            if ($value <= 0.0) {
                continue;
            }
            if ($peak === null || $value > $peak) {
                $peak = $value;
                $peakAt = is_numeric($point[0] ?? null)
                    ? CarbonImmutable::createFromTimestampMs((int) $point[0])
                    : null;
            }
        }

        if ($peak === null) {
            // "Listed" but circulating supply not verified — all-zero array.
            return CoinGeckoLookup::noMarketCap($coinId);
        }

        if ($peak < $threshold) {
            return new CoinGeckoLookup(
                'no_market_cap',
                $coinId,
                note: sprintf('coingecko: peak historical market cap $%.0f below threshold', $peak),
            );
        }

        return CoinGeckoLookup::verified($coinId, $peak, $peakAt ?? $windowEnd, $windowStart, $windowEnd);
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

    private const NOT_FOUND = '__not_found__';

    /**
     * Cached GET. Returns the decoded array, the NOT_FOUND sentinel on a clean
     * 404, or null on any other failure / budget exhaustion.
     *
     * @param  array<string,mixed>  $query
     * @return array<mixed>|string|null
     */
    private function getJson(string $cacheKey, string $path, array $query = []): array|string|null
    {
        /** @var array<mixed>|string|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->callsMade >= $this->maxCallsPerRun) {
            Log::info('CoinGecko per-run call budget exhausted', ['path' => $path]);

            return null;
        }

        $this->callsMade++;

        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('CoinGecko request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 404) {
            Cache::put($cacheKey, self::NOT_FOUND, $this->cacheTtl);

            return self::NOT_FOUND;
        }

        if ($response->status() === 429) {
            Log::warning('CoinGecko rate limited (429)', [
                'path' => $path,
                'retry_after' => $response->header('Retry-After'),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('CoinGecko non-success status', ['path' => $path, 'status' => $response->status()]);

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
        $headers = ['User-Agent' => 'memecoin-detector/1.0 (+sprint1-historical)'];
        if ($this->apiKey !== null) {
            $headers[$this->apiKeyHeader] = $this->apiKey;
        }

        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(2, $this->retrySleepMs, function (Throwable $e): bool {
                return $e instanceof RequestException
                    && $e->response !== null
                    && $e->response->status() === 429;
            }, throw: false);
    }
}
