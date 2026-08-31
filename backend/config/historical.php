<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Historical peak qualification (Strategy D)
    |--------------------------------------------------------------------------
    |
    | Runs AFTER DexScreener normalization + age filter. Determines whether a
    | token has EVER reached the qualification threshold, using tiered evidence:
    |
    |   CURRENT_OBSERVATION  our own snapshot saw market_cap >= threshold
    |   HISTORICAL_VERIFIED  CoinGecko has a non-zero historical market cap point
    |   HISTORICAL_ESTIMATE  GeckoTerminal historical high price x immutable
    |                        total supply (FDV basis — NOT verified market cap)
    |   UNKNOWN              no safe evidence (never "did not reach threshold")
    |
    | See docs/historical-peak-reconnaissance.md and docs/sprint-1-discovery.md.
    |
    */

    // The $5M line. Defaults to the same value the observed-peak filter uses so
    // the two paths stay consistent.
    'min_peak_market_cap_usd' => (int) env(
        'HISTORICAL_MIN_PEAK_USD',
        (int) env('MEMECOIN_OBSERVED_PEAK_MIN_USD', 5_000_000),
    ),

    // Don't re-hit the external providers for the same token more often than
    // this. UNKNOWN and HISTORICAL_ESTIMATE tokens are re-checked once the
    // cooldown expires (so an un-indexed token can later become VERIFIED).
    // CURRENT_OBSERVATION is re-evaluated every run for free; HISTORICAL_VERIFIED
    // is terminal.
    'lookup_cooldown_hours' => (int) env('HISTORICAL_LOOKUP_COOLDOWN_HOURS', 6),

    // Hard ceiling on external historical lookups performed in a single
    // discovery run — protects provider rate limits regardless of how many
    // age-eligible candidates need one.
    'max_lookups_per_run' => (int) env('HISTORICAL_MAX_LOOKUPS_PER_RUN', 15),

    /*
    | CoinGecko — HISTORICAL_VERIFIED source. Optional and resilient: any
    | failure (404, 429, timeout, all-zero market caps) falls through to
    | GeckoTerminal. Never throws into the pipeline.
    */
    'coingecko' => [
        'enabled' => (bool) env('COINGECKO_ENABLED', true),
        'base_url' => rtrim((string) env('COINGECKO_BASE_URL', 'https://api.coingecko.com/api/v3'), '/'),
        // Optional. Demo key -> sent as `x-cg-demo-api-key`. Never exposed to React.
        'api_key' => env('COINGECKO_API_KEY'),
        'api_key_header' => env('COINGECKO_API_KEY_HEADER', 'x-cg-demo-api-key'),
        'timeout' => (int) env('COINGECKO_TIMEOUT', 8),
        'connect_timeout' => (int) env('COINGECKO_CONNECT_TIMEOUT', 4),
        'retry_sleep_ms' => (int) env('COINGECKO_RETRY_SLEEP_MS', 1_000),
        // Cache TTL for a provider response (seconds). Historical data barely
        // changes, so cache it hard.
        'cache_ttl' => (int) env('COINGECKO_CACHE_TTL', 21_600),
        // Per-run call ceiling (keyless CoinGecko rate-limits aggressively).
        'max_calls_per_run' => (int) env('COINGECKO_MAX_CALLS_PER_RUN', 20),
    ],

    /*
    | GeckoTerminal — HISTORICAL_ESTIMATE source (price history only; the market
    | cap is reconstructed here, never provided). Free, no key.
    */
    'geckoterminal' => [
        'enabled' => (bool) env('GECKOTERMINAL_ENABLED', true),
        'base_url' => rtrim((string) env('GECKOTERMINAL_BASE_URL', 'https://api.geckoterminal.com/api/v2'), '/'),
        'timeout' => (int) env('GECKOTERMINAL_TIMEOUT', 8),
        'connect_timeout' => (int) env('GECKOTERMINAL_CONNECT_TIMEOUT', 4),
        'retry_sleep_ms' => (int) env('GECKOTERMINAL_RETRY_SLEEP_MS', 1_000),
        'cache_ttl' => (int) env('GECKOTERMINAL_CACHE_TTL', 21_600),
        'max_calls_per_run' => (int) env('GECKOTERMINAL_MAX_CALLS_PER_RUN', 45),

        'estimate' => [
            // Allow a HISTORICAL_ESTIMATE only when we can POSITIVELY confirm the
            // supply is immutable (Solana: mint_authority === null via
            // /tokens/{addr}/info). When true, a token on a chain with no
            // mint-authority signal (most EVM chains) can still get an estimate
            // if total supply is present — at `low` confidence. Default false =
            // conservative: no positive immutability signal -> UNKNOWN.
            'allow_without_mint_signal' => (bool) env('HISTORICAL_ESTIMATE_ALLOW_UNVERIFIED_SUPPLY', false),
        ],
    ],

    /*
    | DexScreener chain slug -> { coingecko asset-platform id, geckoterminal
    | network id }. An extensible allow-list — a token on an unmapped chain
    | skips the external lookup (CURRENT_OBSERVATION still works).
    */
    'chain_map' => [
        'ethereum' => ['coingecko' => 'ethereum', 'geckoterminal' => 'eth'],
        'solana' => ['coingecko' => 'solana', 'geckoterminal' => 'solana'],
        'bsc' => ['coingecko' => 'binance-smart-chain', 'geckoterminal' => 'bsc'],
        'base' => ['coingecko' => 'base', 'geckoterminal' => 'base'],
        'arbitrum' => ['coingecko' => 'arbitrum-one', 'geckoterminal' => 'arbitrum'],
        'polygon' => ['coingecko' => 'polygon-pos', 'geckoterminal' => 'polygon_pos'],
        'avalanche' => ['coingecko' => 'avalanche', 'geckoterminal' => 'avax'],
        'optimism' => ['coingecko' => 'optimistic-ethereum', 'geckoterminal' => 'optimism'],
        'pulsechain' => ['coingecko' => 'pulsechain', 'geckoterminal' => 'pulsechain'],
    ],
];
