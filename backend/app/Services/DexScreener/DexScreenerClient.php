<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper around the DexScreener public API.
 *
 * Responsibilities: transport only — timeouts, bounded retries, 429/5xx
 * handling, short-lived response caching, and turning any provider failure into
 * an empty result (never an exception that aborts the whole discovery run).
 *
 * No business logic lives here. Endpoint + rate-limit notes:
 * docs/dexscreener-reconnaissance.md.
 */
class DexScreenerClient
{
    /** @var array{timeout:int,connect_timeout:int,retries:int,retry_sleep_ms:int,user_agent:string} */
    private array $http;

    private string $baseUrl;

    private int $discoveryTtl;

    private int $enrichmentTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('dexscreener.base_url'), '/');
        $this->http = config('dexscreener.http');
        $this->discoveryTtl = (int) config('dexscreener.cache.discovery_ttl');
        $this->enrichmentTtl = (int) config('dexscreener.cache.enrichment_ttl');
    }

    /** @return list<array<string,mixed>> Latest token profiles (thin: chainId + tokenAddress + links). */
    public function latestTokenProfiles(): array
    {
        return $this->cachedGet('dexscreener:token-profiles:latest', $this->discoveryTtl, '/token-profiles/latest/v1');
    }

    /** @return list<array<string,mixed>> Latest paid token boosts. */
    public function latestTokenBoosts(): array
    {
        return $this->cachedGet('dexscreener:token-boosts:latest', $this->discoveryTtl, '/token-boosts/latest/v1');
    }

    /** @return list<array<string,mixed>> Tokens with the largest cumulative boost. */
    public function topTokenBoosts(): array
    {
        return $this->cachedGet('dexscreener:token-boosts:top', $this->discoveryTtl, '/token-boosts/top/v1');
    }

    /** @return list<array<string,mixed>> Trending "metas" (narrative aggregates, not tokens). */
    public function trendingMetas(): array
    {
        return $this->cachedGet('dexscreener:metas:trending', $this->discoveryTtl, '/metas/trending/v1');
    }

    /**
     * Free-text pair search. Returns the `pairs` array (≤ ~30, mixed chains).
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $payload = $this->cachedGet(
            'dexscreener:search:'.md5(mb_strtolower($term)),
            $this->discoveryTtl,
            '/latest/dex/search',
            ['q' => $term],
        );

        return $this->pairsFrom($payload);
    }

    /**
     * Primary enrichment endpoint (`/token-pairs/v1/{chainId}/{tokenAddress}`):
     * every pair for many tokens.
     *
     * Cache hits are served immediately; misses are fetched in small concurrent
     * batches (bounded — never an unbounded fan-out) to keep the discovery
     * request responsive while staying well under 300 req/min.
     *
     * @param  list<array{chain_id:string,token_address:string}>  $tokens
     * @return array<string,list<array<string,mixed>>> keyed by "chainId:tokenAddress" (lowercased)
     */
    public function tokenPairsBatch(array $tokens): array
    {
        $concurrency = max(1, (int) config('dexscreener.http.enrich_concurrency', 8));

        /** @var array<string,list<array<string,mixed>>> $results */
        $results = [];
        /** @var list<array{key:string,chain:string,address:string,cache_key:string}> $misses */
        $misses = [];

        foreach ($tokens as $token) {
            $chain = trim((string) ($token['chain_id'] ?? ''));
            $address = trim((string) ($token['token_address'] ?? ''));

            if ($chain === '' || $address === '') {
                continue;
            }

            $key = mb_strtolower($chain).':'.mb_strtolower($address);
            $cacheKey = 'dexscreener:token-pairs:'.$key;

            /** @var array<mixed>|null $cached */
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $results[$key] = array_values(array_filter($cached, 'is_array'));

                continue;
            }

            $misses[] = ['key' => $key, 'chain' => $chain, 'address' => $address, 'cache_key' => $cacheKey];
        }

        foreach (array_chunk($misses, $concurrency) as $chunk) {
            /** @var array<int,Response|Throwable> $responses */
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $m) => $this->poolRequest($pool)
                    ->get('/token-pairs/v1/'.rawurlencode($m['chain']).'/'.rawurlencode($m['address'])),
                $chunk,
            ));

            foreach ($chunk as $i => $miss) {
                $pairs = $this->pairsFromPoolResponse($responses[$i] ?? null, $miss['key']);

                if ($pairs !== null) {
                    Cache::put($miss['cache_key'], $pairs, $this->enrichmentTtl);
                    $results[$miss['key']] = $pairs;
                } else {
                    $results[$miss['key']] = [];
                }
            }
        }

        return $results;
    }

    /**
     * Cache-then-fetch. Only successful responses are cached; failures return
     * `[]` and are re-attempted on the next call.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>|array<string,mixed>
     */
    private function cachedGet(string $cacheKey, int $ttl, string $path, array $query = []): array
    {
        /** @var array<mixed>|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->get($path, $query);

        if ($data === null) {
            return [];
        }

        Cache::put($cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * Perform one GET with retries. Returns the decoded body, or `null` on any
     * failure (connection, non-2xx after retries, malformed body).
     *
     * @param  array<string,mixed>  $query
     * @return array<mixed>|null
     */
    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            Log::warning('DexScreener request failed (connection)', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('DexScreener request failed (unexpected)', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('DexScreener returned a non-success status', [
                'path' => $path,
                'status' => $response->status(),
                // Deliberately truncated — never dump full provider payloads.
                'body_snippet' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['User-Agent' => $this->http['user_agent']])
            ->timeout($this->http['timeout'])
            ->connectTimeout($this->http['connect_timeout'])
            ->retry(
                max(1, $this->http['retries'] + 1),
                $this->http['retry_sleep_ms'],
                function (Throwable $e): bool {
                    if ($e instanceof ConnectionException) {
                        return true;
                    }

                    if ($e instanceof RequestException && $e->response !== null) {
                        $status = $e->response->status();

                        return $status === 429 || $status >= 500;
                    }

                    return false;
                },
                throw: false,
            );
    }

    private function poolRequest(Pool $pool): PendingRequest
    {
        return $pool->baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['User-Agent' => $this->http['user_agent']])
            ->timeout($this->http['timeout'])
            ->connectTimeout($this->http['connect_timeout']);
    }

    /**
     * Decode one pooled `/token-pairs/v1` response. Returns the pair list, or
     * `null` on any failure (so the caller can record it and move on).
     *
     * @return list<array<string,mixed>>|null
     */
    private function pairsFromPoolResponse(Response|Throwable|null $response, string $tokenKey): ?array
    {
        if (! $response instanceof Response) {
            Log::warning('DexScreener enrichment failed (transport)', [
                'token' => $tokenKey,
                'error' => $response instanceof Throwable ? $response->getMessage() : 'no response',
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('DexScreener enrichment returned a non-success status', [
                'token' => $tokenKey,
                'status' => $response->status(),
                'body_snippet' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function pairsFrom(array $payload): array
    {
        $pairs = $payload['pairs'] ?? [];

        if (! is_array($pairs)) {
            return [];
        }

        return array_values(array_filter($pairs, 'is_array'));
    }
}
