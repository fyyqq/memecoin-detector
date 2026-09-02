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
    |--------------------------------------------------------------------------
    | Discovery sources (Step 19 — trending-meta-first)
    |--------------------------------------------------------------------------
    |
    | Priority: trending meta > profiles > boosts > keyword search.
    |
    | The PRIMARY source is DexScreener's documented Trending Meta API
    | (GET /metas/trending/v1 -> GET /metas/meta/v1/{slug}). DexScreener's real
    | per-pair Trending table uses an UNDOCUMENTED WebSocket (io.dexscreener.com)
    | behind Cloudflare bot management and is deliberately NOT used — see
    | docs/trending-discovery-reconnaissance.md.
    |
    | Keyword search (SearchTermEngine + /latest/dex/search) is a SUPPLEMENTAL
    | long-tail fallback, OFF by default. It never overrides trending-meta
    | discovery.
    */
    'discovery_sources' => [
        'trending_meta_enabled' => filter_var(env('DEXSCREENER_TRENDING_META_ENABLED', true), FILTER_VALIDATE_BOOL),
        // How many entries from /metas/trending/v1 to expand via /metas/meta/v1.
        // Reconnaissance observed ~18; do not assume exactly 18 forever.
        'trending_meta_limit' => max(0, (int) env('DEXSCREENER_TRENDING_META_LIMIT', 18)),
        'profiles_enabled' => filter_var(env('DEXSCREENER_PROFILES_ENABLED', true), FILTER_VALIDATE_BOOL),
        'boosts_enabled' => filter_var(env('DEXSCREENER_BOOSTS_ENABLED', true), FILTER_VALIDATE_BOOL),
        // Fallback keyword discovery. OFF by default — NOT primary discovery.
        'keyword_enabled' => filter_var(env('MEMECOIN_KEYWORD_DISCOVERY_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Category A — core meme search terms for the /latest/dex/search sweep.
    | Curated, easy to edit, NOT exhaustive. Highest priority in the search-term
    | engine (see App\Services\DexScreener\SearchTermEngine). Only consulted when
    | keyword discovery is enabled (fallback).
    */
    'search_terms' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'MEMECOIN_SEARCH_TERMS',
            'pepe,doge,cat,dog,frog,wif,inu,meme,shib,bonk,elon,ai,trump,politics,animal',
        )),
    ))),

    'search' => [

        /*
        | Category C — ecosystem / chain names used ONLY as supplementary
        | discovery signals. DexScreener's `/latest/dex/search` is GLOBAL — these
        | are NOT chain filters and do not guarantee results on that chain.
        | Lowest priority in the engine.
        */
        'ecosystem_terms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MEMECOIN_ECOSYSTEM_TERMS', 'solana,base,ethereum,bsc,arbitrum')),
        ))),

        /*
        | Total number of search terms per run, after core + trending-meta +
        | ecosystem are merged and de-duplicated. Keeps the sweep well inside the
        | 300 req/min search budget.
        */
        'term_budget' => max(0, (int) env('MEMECOIN_SEARCH_TERM_BUDGET', 25)),
    ],

    /*
    | Category B — how many trending "meta" entries (from /metas/trending/v1) to
    | consider for search terms. Each contributes a slug and a name. The
    | `search.term_budget` above is the real ceiling.
    */
    'trending_meta_terms' => (int) env('DEXSCREENER_TRENDING_META_TERMS', 8),

    /*
    | Sprint 1 eligibility (Step 19 — bounded market-cap universe):
    |
    |   age <= max_age_days
    |   AND $5M <= qualifying_peak < $1B
    |
    | where qualifying_peak is the highest VERIFIED / OBSERVED market cap we trust
    | (CURRENT_OBSERVATION or HISTORICAL_VERIFIED). A token that ONCE printed a
    | peak at or above the ceiling is excluded even if its current MC is far
    | lower. A token whose CURRENT MC has dumped below the floor STAYS qualified
    | if it already cleared the floor via an earlier observation / historical
    | evidence — the lower bound is a peak rule, not a current-MC rule.
    |
    | The floor is INCLUSIVE (peak >= $5M qualifies) and the ceiling is EXCLUSIVE
    | (peak < $1B; a peak of exactly $1B does NOT qualify). The configured value
    | stays 1_000_000_000 — the exclusivity is in the `<` comparison at the
    | qualification sites. HISTORICAL_ESTIMATE (FDV basis) never qualifies.
    */
    'filters' => [
        'observed_peak_market_cap_min_usd' => (int) env('MEMECOIN_OBSERVED_PEAK_MIN_USD', 5_000_000),
        'observed_peak_market_cap_max_usd' => (int) env('MEMECOIN_OBSERVED_PEAK_MAX_USD', 1_000_000_000),
        'max_age_days' => (int) env('MEMECOIN_MAX_AGE_DAYS', 30),
        // Loose pre-enrichment age gate for trending-meta pairs — PERFORMANCE
        // ONLY. Final age validation always uses earliest_pair_created_at across
        // ALL of the token's pairs after full enrichment.
        'prefilter_max_age_days' => max(1, (int) env('MEMECOIN_PREFILTER_MAX_AGE_DAYS', 35)),
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
    | "Recently Crossed $5M" (Step 20).
    |
    | `hours` — used by GET /api/memecoins for the per-row `recently_crossed`
    | boolean + `meta.recent_crossing_hours`. A narrow "just crossed" signal on
    | the (UI-less, still-tested) main list. Unchanged.
    |
    | `window_days` — the crossing window for GET /api/memecoins/recently-crossed
    | (the "🔥 Recently Crossed $5M" dashboard section). A token appears there
    | only when its REPRESENTATIVE `qualification_events.crossed_at` is within
    | this many days AND it passes every deterministic quality gate below.
    |
    | The quality gates (all PostgreSQL-only, no provider calls — see
    | App\Services\Historical\RecentlyCrossedQualifier). The three ratio floors
    | below were CALIBRATED against a 9-token empirical reference set of real
    | survivors (see docs/recently-crossed-calibration.md) — the previous
    | 0.001 / 0.001 / 5.0 values were placeholder assumptions ~10-100x weaker
    | than any real survivor:
    |   - discovery freshness: the discovery pipeline must have OBSERVED the
    |     token within `discovery_freshness_hours`. We persist only
    |     `last_observed_at` — NOT which feed (meta / boost / profile) surfaced
    |     it — so this is honestly "recently observed by discovery", never a
    |     claim that the token is "trending".
    |   - risk screen: reuses App\Services\Risk\MainListDecision (no honeypot /
    |     cannot-buy / cannot-sell / mintable / CRITICAL / HIGH / hard override)
    |     WITHOUT the main-list >=72h maturity gate. A RISK-UNKNOWN /
    |     unscreened / low-completeness result rejects only on a chain our
    |     security provider covers (`risk.goplus_chain_map`); on an unsupported
    |     chain (e.g. `robinhood`) that is EXPECTED and does not reject by
    |     itself — see `allow_unsupported_chain_risk_unknown`. Positive
    |     hard-failure signals reject on every chain.
    |   - holder participation: when a MEASURED `holder_count` risk signal
    |     exists, holders / ($MC / 1e6) must be >= `min_holders_per_million_mcap`
    |     (reference survivors: 552-3,484 per $1M; anomalies like $50M / 20
    |     holders = 0.4). A MISSING count no longer rejects by default
    |     (`require_holder_evidence` = false) — unsupported chains cannot produce
    |     one and 3/9 reference survivors are on such a chain. Never fabricated.
    |   - 24h volume vs CURRENT market cap: `volume_h24 / current_market_cap`
    |     must be >= `min_volume_to_mcap_ratio` (reference survivors: 0.035-0.75;
    |     the $50M MC / $7.2K volume anomaly = 0.000144). Never FDV, never peak
    |     MC. High volume is never a reject.
    |   - liquidity: `liquidity_usd` >= risk.liquidity.min_total_usd AND
    |     >= current MC * `min_liquidity_to_mcap_ratio` (reference survivors:
    |     0.016-0.32 of current MC).
    */
    'recent_crossing' => [
        'hours' => max(1, (int) env('MEMECOIN_RECENT_CROSSING_HOURS', 48)),
        'max_hours' => max(1, (int) env('MEMECOIN_RECENT_CROSSING_MAX_HOURS', 168)),

        'window_days' => max(1, (int) env('MEMECOIN_RECENT_CROSSING_WINDOW_DAYS', 30)),

        'discovery_freshness_hours' => max(1, (int) env('MEMECOIN_RECENT_CROSSING_DISCOVERY_FRESHNESS_HOURS', 48)),

        // Holder floor. Calibrated: reference survivors sit at 552-3,484 holders
        // per $1M of current MC; 25 is a deliberately loose floor — an order of
        // magnitude below the weakest survivor and an order of magnitude above
        // the $50M / 20-60 holder anomalies (0.4-1.2 per $1M). The risk screen's
        // `per_million_reference` (50) is its SOFT "thin" warning; this HARD
        // reject sits below it on purpose.
        'min_holders_per_million_mcap' => (float) env('MEMECOIN_RECENT_CROSSING_MIN_HOLDERS_PER_MILLION_MCAP', 25.0),
        // When true, a token with NO measured holder count is rejected. Default
        // false: chains without security-provider coverage (e.g. robinhood)
        // can never produce a holder count, and real survivors live there. The
        // ratio floor above still applies whenever a count IS available.
        'require_holder_evidence' => filter_var(env('MEMECOIN_RECENT_CROSSING_REQUIRE_HOLDER_EVIDENCE', false), FILTER_VALIDATE_BOOL),

        // 24h volume must be at least this fraction of CURRENT market cap.
        // Calibrated: weakest reference survivor = 0.035; 0.01 keeps a ~3.5x
        // margin while rejecting near-dead markets (the $50M MC / $7.2K volume
        // anomaly = 0.000144). A quiet legitimate day still clears 1%.
        'min_volume_to_mcap_ratio' => (float) env('MEMECOIN_RECENT_CROSSING_MIN_VOLUME_TO_MCAP_RATIO', 0.01),

        // Relative liquidity floor (on top of the absolute
        // risk.liquidity.min_total_usd). Calibrated: weakest reference survivor
        // = 0.016 of current MC; 0.005 keeps a ~3x margin and rejects the
        // "high MC / negligible liquidity" shape.
        'min_liquidity_to_mcap_ratio' => (float) env('MEMECOIN_RECENT_CROSSING_MIN_LIQUIDITY_TO_MCAP_RATIO', 0.005),

        // A RISK-UNKNOWN / unscreened / low-completeness risk result rejects a
        // token only on a chain our security provider (`risk.goplus_chain_map`)
        // actually covers. On an unsupported chain that outcome is expected and
        // does NOT reject by itself — positive hard-failure signals (honeypot,
        // cannot-buy, cannot-sell, mintable, CRITICAL, HIGH, hard override)
        // still reject everywhere.
        'allow_unsupported_chain_risk_unknown' => filter_var(env('MEMECOIN_RECENT_CROSSING_ALLOW_UNSUPPORTED_CHAIN_RISK_UNKNOWN', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Safety ceilings so a large ?limit= cannot blow up the fan-out.
    */
    'limits' => [
        'default_result_limit' => (int) env('MEMECOIN_DEFAULT_LIMIT', 20),
        'max_result_limit' => (int) env('MEMECOIN_MAX_LIMIT', 50),
        // Hard ceiling on the UNIQUE candidate set kept per run, before
        // prioritization + enrichment. Independent of ?limit= and of the
        // enrichment ceiling below.
        'discovery_candidate_cap' => max(1, (int) env('MEMECOIN_DISCOVERY_CANDIDATE_CAP', 500)),
        // Hard ceiling on enrichment calls per run, regardless of ?limit=.
        'max_candidates_to_enrich' => (int) env('MEMECOIN_MAX_ENRICH', 120),
    ],

];
