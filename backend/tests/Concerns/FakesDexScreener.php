<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP fake for the DexScreener endpoints. The fakes are registered once
 * and read a mutable fixture, so a test can change the responses between
 * successive discovery calls (re-calling Http::fake() would merge stubs and the
 * first match would always win).
 */
trait FakesDexScreener
{
    /** @var array<string,mixed> */
    private array $dexFixture = [
        'profiles' => [],
        'latestBoosts' => [],
        'topBoosts' => [],
        'metas' => [],
        /** slug => { name, pairs: [...] } */
        'metaDetails' => [],
        'searchPairs' => [],
        'tokenPairs' => [],
    ];

    private bool $dexHttpFaked = false;

    protected function bootDexScreenerFakeConfig(): void
    {
        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.search.ecosystem_terms', []);
        config()->set('dexscreener.search.term_budget', 25);
        config()->set('dexscreener.trending_meta_terms', 0);
        config()->set('dexscreener.limits.discovery_candidate_cap', 500);
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.http.retries', 0);
        config()->set('dexscreener.http.retry_sleep_ms', 0);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);

        // Default for the shared fake: keyword-search discovery path (Step 19
        // fallback). Trending-meta tests flip these explicitly.
        config()->set('dexscreener.discovery_sources.trending_meta_enabled', false);
        config()->set('dexscreener.discovery_sources.profiles_enabled', true);
        config()->set('dexscreener.discovery_sources.boosts_enabled', true);
        config()->set('dexscreener.discovery_sources.keyword_enabled', true);

        // Historical qualification is exercised by HistoricalQualificationTest;
        // keep the DexScreener-pipeline tests focused (and offline).
        config()->set('historical.coingecko.enabled', false);
        config()->set('historical.geckoterminal.enabled', false);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function dexPair(string $chainId, string $tokenAddress, array $overrides = []): array
    {
        return array_replace([
            'chainId' => $chainId,
            'dexId' => 'raydium',
            'pairAddress' => 'PAIR-'.substr(md5($chainId.$tokenAddress.serialize($overrides)), 0, 10),
            'baseToken' => ['address' => $tokenAddress, 'name' => ucfirst($tokenAddress), 'symbol' => 'TKN'],
            'quoteToken' => ['address' => 'QUOTE', 'symbol' => 'SOL'],
            'priceUsd' => '0.01',
            'liquidity' => ['usd' => 250_000.0],
            'volume' => ['h24' => 50_000.0],
            'priceChange' => ['h24' => 3.3],
            'txns' => ['h24' => ['buys' => 20, 'sells' => 12]],
            'fdv' => 6_000_000.0,
            'marketCap' => 6_000_000.0,
            'pairCreatedAt' => CarbonImmutable::now()->subDays(10)->getTimestampMs(),
        ], $overrides);
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $tokenPairs  keyed by "chain:address"
     * @param  array<string,mixed>  $extra
     */
    protected function fakeDexScreener(array $tokenPairs, array $extra = []): void
    {
        $this->dexFixture['tokenPairs'] = $tokenPairs;
        $this->dexFixture['profiles'] = $extra['profiles'] ?? [];
        $this->dexFixture['latestBoosts'] = $extra['latestBoosts'] ?? [];
        $this->dexFixture['topBoosts'] = $extra['topBoosts'] ?? [];
        $this->dexFixture['metas'] = $extra['metas'] ?? [];
        $this->dexFixture['metaDetails'] = $extra['metaDetails'] ?? [];
        $this->dexFixture['searchPairs'] = $extra['searchPairs'] ?? array_map(function (string $key): array {
            [$chain, $addr] = explode(':', $key, 2);

            return [
                'chainId' => $chain,
                'baseToken' => ['address' => $addr, 'symbol' => 'TKN'],
                'pairAddress' => 'SEARCH-'.substr(md5($key), 0, 8),
            ];
        }, array_keys($tokenPairs));

        if ($this->dexHttpFaked) {
            return;
        }

        $this->dexHttpFaked = true;

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response($this->dexFixture['profiles']),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response($this->dexFixture['latestBoosts']),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response($this->dexFixture['topBoosts']),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response($this->dexFixture['metas']),
            'api.dexscreener.com/metas/meta/v1/*' => function (Request $request) {
                $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
                $segments = array_values(array_filter(explode('/', $path)));
                $slug = urldecode($segments[count($segments) - 1] ?? '');

                return Http::response($this->dexFixture['metaDetails'][$slug] ?? []);
            },
            'api.dexscreener.com/latest/dex/search*' => fn () => Http::response([
                'schemaVersion' => '1.0.0',
                'pairs' => $this->dexFixture['searchPairs'],
            ]),
            'api.dexscreener.com/token-pairs/v1/*' => function (Request $request) {
                $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
                $segments = array_values(array_filter(explode('/', $path)));
                $chain = strtolower($segments[count($segments) - 2] ?? '');
                $addr = strtolower($segments[count($segments) - 1] ?? '');

                foreach ($this->dexFixture['tokenPairs'] as $key => $pairs) {
                    if (strtolower($key) === "{$chain}:{$addr}") {
                        return Http::response($pairs);
                    }
                }

                return Http::response([]);
            },
        ]);
    }
}
