<?php

declare(strict_types=1);

namespace App\Services\Risk;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GeckoTerminal `/info` adapter — SECONDARY risk verification (Step 24).
 *
 * Reuses the config/historical.php GeckoTerminal credentials + chain map. Free,
 * no key. Every failure returns a non-OK {@see GeckoTerminalInfoLookup} — never
 * throws. Bounded by its own per-run call budget; responses cached hard.
 */
class GeckoTerminalInfoClient
{
    private bool $enabled;

    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $retrySleepMs;

    private int $cacheTtl;

    private int $maxCallsPerRun;

    /** @var array<string,array{coingecko:string,geckoterminal:string}> */
    private array $chainMap;

    private int $callsMade = 0;

    public function __construct()
    {
        $gt = config('historical.geckoterminal');
        $this->enabled = (bool) config('risk.geckoterminal.enabled', false)
            && (bool) ($gt['enabled'] ?? false);
        $this->baseUrl = rtrim((string) ($gt['base_url'] ?? ''), '/');
        $this->timeout = (int) ($gt['timeout'] ?? 8);
        $this->connectTimeout = (int) ($gt['connect_timeout'] ?? 4);
        $this->retrySleepMs = (int) ($gt['retry_sleep_ms'] ?? 1_000);
        $this->cacheTtl = (int) ($gt['cache_ttl'] ?? 21_600);
        $this->maxCallsPerRun = (int) config('risk.geckoterminal.max_calls_per_run', 30);
        /** @var array<string,array{coingecko:string,geckoterminal:string}> $map */
        $map = config('historical.chain_map', []);
        $this->chainMap = $map;
    }

    public function resetBudget(): void
    {
        $this->callsMade = 0;
    }

    public function callsMade(): int
    {
        return $this->callsMade;
    }

    public function info(string $chainId, string $tokenAddress): GeckoTerminalInfoLookup
    {
        if (! $this->enabled) {
            return GeckoTerminalInfoLookup::disabled();
        }

        $network = $this->chainMap[mb_strtolower($chainId)]['geckoterminal'] ?? null;
        if ($network === null) {
            return GeckoTerminalInfoLookup::unsupportedChain($chainId);
        }

        $body = $this->getJson(
            "risk:gtinfo:{$network}:".mb_strtolower($tokenAddress),
            "/networks/{$network}/tokens/".rawurlencode($tokenAddress).'/info',
        );

        if ($body === null) {
            return GeckoTerminalInfoLookup::error('geckoterminal /info request failed');
        }

        $attr = data_get($body, 'data.attributes');
        if (! is_array($attr) || $attr === []) {
            return GeckoTerminalInfoLookup::notIndexed();
        }

        return GeckoTerminalInfoLookup::ok($attr);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJson(string $cacheKey, string $path): ?array
    {
        /** @var array<string,mixed>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->callsMade >= $this->maxCallsPerRun) {
            Log::info('GeckoTerminal /info per-run budget exhausted', ['path' => $path]);

            return null;
        }
        $this->callsMade++;

        try {
            $response = $this->request()->get($path);
        } catch (Throwable $e) {
            Log::warning('GeckoTerminal /info request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429 || $response->failed()) {
            Log::warning('GeckoTerminal /info non-success', ['path' => $path, 'status' => $response->status()]);

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
            ->withHeaders(['User-Agent' => 'memecoin-detector/1.0 (+step24-risk)'])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(2, $this->retrySleepMs, function (Throwable $e): bool {
                return $e instanceof RequestException
                    && $e->response !== null
                    && $e->response->status() === 429;
            }, throw: false);
    }
}
