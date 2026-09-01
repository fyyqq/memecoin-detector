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
 * GoPlus Security API adapter — the primary security provider (Step 24).
 *
 * Free, no key required (an optional App Key raises limits). EVM chains use
 * `token_security/{numeric_chain_id}`; Solana uses the dedicated
 * `solana/token_security` (a DIFFERENT response schema — see
 * {@see GoPlusSecurityLookup}).
 *
 * Every failure returns a non-OK {@see GoPlusSecurityLookup} — it NEVER throws
 * into the pipeline. Bounded by `max_calls_per_run`; responses cached hard.
 * The App Key / secret is server-side only and is never exposed to React.
 */
class GoPlusSecurityClient
{
    private bool $enabled;

    private string $baseUrl;

    private ?string $appKey;

    private int $timeout;

    private int $connectTimeout;

    private int $retrySleepMs;

    private int $cacheTtl;

    private int $maxCallsPerRun;

    /** @var array<string,string> */
    private array $chainMap;

    private int $callsMade = 0;

    public function __construct()
    {
        $cfg = config('risk.goplus');
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->appKey = $cfg['app_key'] ?? null;
        $this->timeout = (int) ($cfg['timeout'] ?? 8);
        $this->connectTimeout = (int) ($cfg['connect_timeout'] ?? 4);
        $this->retrySleepMs = (int) ($cfg['retry_sleep_ms'] ?? 1_000);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? 21_600);
        $this->maxCallsPerRun = (int) ($cfg['max_calls_per_run'] ?? 60);
        /** @var array<string,string> $map */
        $map = config('risk.goplus_chain_map', []);
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

    /**
     * Fetch + normalize the security profile for one token.
     */
    public function security(string $chainId, string $tokenAddress): GoPlusSecurityLookup
    {
        if (! $this->enabled) {
            return GoPlusSecurityLookup::disabled();
        }

        $chainId = mb_strtolower($chainId);
        $goPlusChain = $this->chainMap[$chainId] ?? null;

        if ($goPlusChain === null) {
            return GoPlusSecurityLookup::unsupportedChain($chainId);
        }

        return $goPlusChain === 'solana'
            ? $this->solana($tokenAddress)
            : $this->evm($goPlusChain, $tokenAddress);
    }

    private function evm(string $numericChainId, string $tokenAddress): GoPlusSecurityLookup
    {
        $addr = mb_strtolower($tokenAddress);
        $body = $this->getJson(
            "risk:goplus:evm:{$numericChainId}:{$addr}",
            "/token_security/{$numericChainId}",
            ['contract_addresses' => $addr],
        );

        if ($body === null) {
            return GoPlusSecurityLookup::error('goplus evm request failed');
        }

        $result = $this->pickResult($body, $addr);
        if ($result === null) {
            return GoPlusSecurityLookup::notIndexed('evm');
        }

        // Best-effort rug-pull composite (EVM only). Merged under a private key
        // so the evaluator can read it without a second lookup object.
        $rug = $this->getJson(
            "risk:goplus:rug:{$numericChainId}:{$addr}",
            "/rugpull_detecting/{$numericChainId}",
            ['contract_addresses' => $addr],
        );
        if (is_array($rug)) {
            $rugResult = $this->pickResult($rug, $addr);
            if (is_array($rugResult)) {
                $result['_rugpull'] = $rugResult;
            }
        }

        return GoPlusSecurityLookup::ok('evm', $result);
    }

    private function solana(string $tokenAddress): GoPlusSecurityLookup
    {
        $body = $this->getJson(
            'risk:goplus:solana:'.$tokenAddress,
            '/solana/token_security',
            ['contract_addresses' => $tokenAddress],
        );

        if ($body === null) {
            return GoPlusSecurityLookup::error('goplus solana request failed');
        }

        // Solana keys the result by the exact (case-sensitive) address.
        $result = $this->pickResult($body, $tokenAddress, caseInsensitive: false);
        if ($result === null) {
            return GoPlusSecurityLookup::notIndexed('solana');
        }

        return GoPlusSecurityLookup::ok('solana', $result);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>|null
     */
    private function pickResult(array $body, string $address, bool $caseInsensitive = true): ?array
    {
        $result = $body['result'] ?? null;
        if (! is_array($result) || $result === []) {
            return null;
        }

        foreach ($result as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === $address
                || ($caseInsensitive && is_string($key) && mb_strtolower($key) === mb_strtolower($address))) {
                return $value;
            }
        }

        // Some responses key by a single entry regardless of casing.
        $first = reset($result);

        return is_array($first) ? $first : null;
    }

    /**
     * Cached GET. Returns the decoded array, or null on any failure / budget cap.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>|null
     */
    private function getJson(string $cacheKey, string $path, array $query): ?array
    {
        /** @var array<string,mixed>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->callsMade >= $this->maxCallsPerRun) {
            Log::info('GoPlus per-run call budget exhausted', ['path' => $path]);

            return null;
        }
        $this->callsMade++;

        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('GoPlus request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->status() === 429) {
            Log::warning('GoPlus rate limited (429)', ['path' => $path]);

            return null;
        }
        if ($response->failed()) {
            Log::warning('GoPlus non-success status', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return null;
        }

        // GoPlus wraps everything in {code, message, result}. code 1 == ok.
        Cache::put($cacheKey, $decoded, $this->cacheTtl);

        return $decoded;
    }

    private function request(): PendingRequest
    {
        $headers = ['User-Agent' => 'memecoin-detector/1.0 (+step24-risk)'];
        if (is_string($this->appKey) && $this->appKey !== '') {
            // GoPlus accepts an Access Token via the Authorization header when a
            // paid App Key is configured. Server-side only.
            $headers['Authorization'] = $this->appKey;
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
