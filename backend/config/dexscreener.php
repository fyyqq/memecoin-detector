<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DexScreener public API
    |--------------------------------------------------------------------------
    |
    | The base URL always comes from the environment — it is never hardcoded in
    | business logic. See docs/dexscreener-reconnaissance.md for endpoint /
    | rate-limit details.
    |
    */

    'base_url' => env('DEXSCREENER_BASE_URL', 'https://api.dexscreener.com'),

    'http' => [
        'timeout' => (int) env('DEXSCREENER_TIMEOUT', 8),
        'connect_timeout' => (int) env('DEXSCREENER_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('DEXSCREENER_RETRIES', 2),
        'retry_sleep_ms' => (int) env('DEXSCREENER_RETRY_SLEEP_MS', 500),
        'user_agent' => env('DEXSCREENER_USER_AGENT', 'memecoin-detector/1.0 (+sprint1-discovery)'),
        // Bounded concurrency for the enrichment batch. Small on purpose — well
        // under the 300 req/min limit, never an unbounded fan-out.
        'enrich_concurrency' => (int) env('DEXSCREENER_ENRICH_CONCURRENCY', 10),
    ],

    /*
    | Response cache TTLs (seconds). DexScreener serves `Cache-Control:
    | public, max-age=60` on most endpoints, so short TTLs are enough and keep
    | us well under the published rate limits.
    */
    'cache' => [
        'discovery_ttl' => (int) env('DEXSCREENER_DISCOVERY_CACHE_TTL', 60),
        'enrichment_ttl' => (int) env('DEXSCREENER_ENRICHMENT_CACHE_TTL', 60),
    ],

    /*
    | Curated memecoin search terms for the /latest/dex/search sweep. This list
    | is intentionally short and easy to edit; it is NOT exhaustive.
    */
    'search_terms' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MEMECOIN_SEARCH_TERMS', 'pepe,doge,cat,dog,wif,inu,meme,shib,bonk,elon')),
    ))),

    /*
    | How many trending meta names/slugs to fold into the search-term sweep.
    */
    'trending_meta_terms' => (int) env('DEXSCREENER_TRENDING_META_TERMS', 5),

    /*
    | Sprint 1 eligibility: age <= max_age_days AND observed_peak_market_cap >=
    | observed_peak_market_cap_min_usd. "Observed peak" = the highest market cap
    | captured by our own snapshots, not a guaranteed lifetime high.
    */
    'filters' => [
        'observed_peak_market_cap_min_usd' => (int) env('MEMECOIN_OBSERVED_PEAK_MIN_USD', 5_000_000),
        'max_age_days' => (int) env('MEMECOIN_MAX_AGE_DAYS', 30),
    ],

    /*
    | Scheduled ingestion. The scheduler runs `memecoins:discover` on this
    | cadence (minutes; clamped to 1..60). Overlap protection means a slow run
    | is never doubled up.
    */
    'discovery' => [
        'interval_minutes' => max(1, min(60, (int) env('MEMECOIN_DISCOVERY_INTERVAL_MINUTES', 10))),
    ],

    /*
    | Safety ceilings so a large ?limit= cannot blow up the fan-out.
    */
    'limits' => [
        'default_result_limit' => (int) env('MEMECOIN_DEFAULT_LIMIT', 20),
        'max_result_limit' => (int) env('MEMECOIN_MAX_LIMIT', 50),
        // Hard ceiling on enrichment calls per run, regardless of ?limit=.
        'max_candidates_to_enrich' => (int) env('MEMECOIN_MAX_ENRICH', 120),
    ],

];
