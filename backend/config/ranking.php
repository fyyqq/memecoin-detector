<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly Top Memecoins (Step 25 — Top 3, participation score)
    |--------------------------------------------------------------------------
    |
    | For EVERY calendar month, the TOP 3 performing memecoins inside each of the
    | five fixed chain buckets (solana / robinhood / bsc / base / other), unique
    | on (year, month, chain_bucket, rank). The score rewards real PARTICIPATION:
    |
    |   strength(x, ref)     = min(1, ln(1 + x) / ln(1 + ref))     (capped-log)
    |   holder_strength      = strength(holder_count,       references.holder_count)
    |   volume_strength      = strength(monthly_volume_usd, references.volume_usd)
    |   market_cap_strength  = strength(month_peak_mc,      references.market_cap_usd)
    |
    |   score = 100 * Σ(weight · strength) / Σ(weight)   over the KNOWN components
    |
    | A `null` holder count is UNKNOWN — it drops out of the sum and the remaining
    | weights renormalize (never silently treated as 0). Market cap is SUPPORTING
    | evidence: a $150M token does NOT automatically beat a $20M token with far
    | stronger holders + volume. All figures come from OBSERVED / VERIFIED data —
    | never FDV, never a historical estimate, never a current count standing in
    | for a past month. Risk score, AI and social sentiment are NEVER used. The
    | score is NOT a prediction of returns.
    |
    | `market_cap_growth_pct` / `peak_expansion_ratio` / `activity` are still
    | computed and shown, but are INFO-ONLY context — never part of the score or
    | the ordering.
    |
    */

    // How many ranked rows per (year, month, chain_bucket). 12 · 5 · 3 = 180/yr.
    'top_n' => max(1, (int) env('MEMECOIN_MONTHLY_TOP_N', 3)),

    // Selection weights (sum need not be 1 — the score renormalizes over the
    // components that are actually known).
    'weights' => [
        'holder' => (float) env('MEMECOIN_MONTHLY_W_HOLDER', 0.40),
        'volume' => (float) env('MEMECOIN_MONTHLY_W_VOLUME', 0.35),
        'market_cap' => (float) env('MEMECOIN_MONTHLY_W_MARKET_CAP', 0.25),
    ],

    // The raw value at which each strength reaches ~1.0.
    //   holder_count 10_000   => a 10k-holder token scores ~1.0 on holders
    //   volume_usd 20M        => a $20M representative monthly volume scores ~1.0
    //   market_cap_usd 50M    => a $50M month-peak MC scores ~1.0 (a $150M token
    //                            is only modestly higher — MC cannot dominate)
    'references' => [
        'holder_count' => (float) env('MEMECOIN_MONTHLY_REF_HOLDERS', 10_000),
        'volume_usd' => (float) env('MEMECOIN_MONTHLY_REF_VOLUME_USD', 20_000_000),
        'market_cap_usd' => (float) env('MEMECOIN_MONTHLY_REF_MARKET_CAP_USD', 50_000_000),
    ],

    // "Never rank by market-cap size alone." A RESEARCHED candidate whose only
    // known participation input is a market cap (no holder count, no volume) has
    // its score multiplied by this — it can still be recorded but can never beat
    // a candidate with real holder + volume evidence. Internal-observed
    // candidates always have volume (an eligibility gate) so this never applies
    // to them.
    'market_cap_only_penalty' => (float) env('MEMECOIN_MONTHLY_MARKET_CAP_ONLY_PENALTY', 0.5),

    /*
    | Monthly holder pass. The current PROVISIONAL month polls GeckoTerminal
    | `/info` (reusing App\Services\Risk\GeckoTerminalInfoClient) for the eligible
    | candidates only, once a day inside `memecoins:finalize-monthly-champion`.
    | It stores the monthly MAX holder count on the ranking rows. There is NO
    | `market_snapshots` change and NO holder capture in the 10-minute discovery
    | loop. A completed past month gets holder data ONLY from an operator seed row.
    */
    'holder_pass' => [
        'enabled' => filter_var(env('MEMECOIN_MONTHLY_HOLDER_PASS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_tokens_per_run' => max(0, (int) env('MEMECOIN_MONTHLY_HOLDER_MAX_TOKENS', 25)),
        'cooldown_hours' => max(0, (int) env('MEMECOIN_MONTHLY_HOLDER_COOLDOWN_HOURS', 20)),
    ],

    /*
    | INFO-ONLY context (`market_cap_growth_pct` / `peak_expansion_ratio` /
    | activity). Deterministic capped-log normalization; shown in the API but
    | never part of the selection score or the ordering.
    */
    'growth_reference' => (float) env('MEMECOIN_MONTHLY_GROWTH_REFERENCE', 20.0),
    'expansion_reference' => (float) env('MEMECOIN_MONTHLY_EXPANSION_REFERENCE', 25.0),

    'activity' => [
        'weights' => [
            'volume' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_WEIGHT_VOLUME', 0.45),
            'liquidity' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_WEIGHT_LIQUIDITY', 0.30),
            'txns' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_WEIGHT_TXNS', 0.20),
            'price_change' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_WEIGHT_PRICE_CHANGE', 0.05),
        ],
        'volume_reference' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_VOLUME_REF', 500_000),
        'liquidity_reference' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_LIQUIDITY_REF', 250_000),
        'txns_reference' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_TXNS_REF', 2_000),
        // Median absolute 24h price change (%) that normalizes to ~1.0.
        'price_change_reference' => (float) env('MEMECOIN_MONTHLY_ACTIVITY_PRICE_CHANGE_REF', 50),
    ],

    /*
    | A token observed only once or twice must NOT win the month. The coverage
    | ratio is (eligible observations) / (expected observations over the token's
    | possible in-month window at the detector's normal cadence). Below the
    | minimum the candidate is `insufficient_observation` and cannot become
    | champion.
    */
    'min_observation_coverage' => (float) env('MEMECOIN_MONTHLY_MIN_OBSERVATION_COVERAGE', 0.25),

    // Detector observation cadence (minutes) used for `expected_observations`.
    // Falls back to the discovery interval.
    'observation_interval_minutes' => (int) env(
        'MEMECOIN_MONTHLY_OBSERVATION_INTERVAL_MINUTES',
        env('MEMECOIN_DISCOVERY_INTERVAL_MINUTES', 10),
    ),

    /*
    |--------------------------------------------------------------------------
    | Chain buckets (Step 25 — Top 3)
    |--------------------------------------------------------------------------
    |
    | For EVERY calendar month, the TOP 3 performing memecoins inside each of the
    | FIVE fixed display buckets: solana, robinhood, bsc, base, other. The unique
    | key is (year, month, chain_bucket, rank) with rank in {1,2,3} — at most
    | 12 x 5 x 3 = 180 rows a year. There is NO global monthly winner. The token
    | keeps its real `chain_id`; only `monthly_rankings.chain_bucket` says
    | "other". The bucket list is fixed in App\Services\Ranking\ChainBucket and
    | is intentionally not env-configurable.
    |
    */

    /*
    | Historical research (memecoins:research-monthly-champions). External
    | research is used ONLY by that command — never by the daily finalize pass
    | and never by the read API. The DexScreener public API does NOT expose a
    | historical monthly Trending leaderboard, and search-engine result pages
    | must not be scraped, so `web_research` is OFF by default: enable it only
    | with a real, configured, reputable historical market-data source.
    */
    'research' => [
        // Ordered provider ids. `internal_observed` uses our own MarketSnapshots
        // (always available). `seed_file` reads operator-verified historical
        // candidates from `seed_path` (the bridge from manual internet research).
        // `web_research` is an OFF-by-default extension point — there is no free
        // official API for a historical monthly Trending leaderboard.
        'providers' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('MEMECOIN_MONTHLY_RESEARCH_PROVIDERS', 'internal_observed,seed_file'),
        )))),
        // Curated JSON of verified historical candidates
        // ({year, month, chain_bucket, name, symbol, chain_id, token_address,
        //  baseline/peak market cap, volume_usd, launch_date, age_uncertain,
        //  source_type, confidence, sources:[{name,url,claim,published_at,credibility}],
        //  explanation}). Never auto-generated from search snippets.
        'seed_path' => env(
            'MEMECOIN_MONTHLY_RESEARCH_SEED_PATH',
            storage_path('app/monthly-champion-candidates.json'),
        ),
        'max_buckets_per_run' => (int) env('MEMECOIN_MONTHLY_RESEARCH_MAX_BUCKETS_PER_RUN', 15),
        'web' => [
            'enabled' => filter_var(env('MEMECOIN_MONTHLY_RESEARCH_WEB_ENABLED', false), FILTER_VALIDATE_BOOL),
            'base_url' => env('MEMECOIN_MONTHLY_RESEARCH_WEB_BASE_URL'),
        ],
    ],
];
