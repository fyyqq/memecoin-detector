<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly Meme Champions (Step 22)
    |--------------------------------------------------------------------------
    |
    | The champion of a calendar month is the single eligible trending memecoin
    | that most strongly OUTPERFORMED the other eligible trending memecoins that
    | month. The score PRIMARILY rewards relative market-cap GROWTH
    | (baseline -> peak within the month), from OBSERVED / VERIFIED market cap
    | only — never FDV, never a historical estimate.
    |
    | The score is a transparent 0..100 figure and is NOT a prediction of future
    | returns. The UI says "observed MC growth", never "profit" / "ROID" / "return".
    |
    */

    // Score = 100 * (w_growth*growth_score + w_expansion*expansion_score
    //                + w_activity*activity_score). Weights are configurable;
    // growth must stay dominant.
    'weights' => [
        'growth' => (float) env('MEMECOIN_MONTHLY_WEIGHT_GROWTH', 0.60),
        'expansion' => (float) env('MEMECOIN_MONTHLY_WEIGHT_EXPANSION', 0.25),
        'activity' => (float) env('MEMECOIN_MONTHLY_WEIGHT_ACTIVITY', 0.15),
    ],

    /*
    | Deterministic capped-log normalization so extreme outliers cannot dominate
    | indefinitely:
    |
    |   growth_score    = min(1, ln(1 + growth_pct/100) / ln(1 + growth_reference))
    |   expansion_score = min(1, ln(peak_expansion_ratio) / ln(expansion_reference))
    |
    | growth_reference 20  => 2000% growth (a ~21x baseline->peak) scores 1.0
    | expansion_reference 25 => a 25x peak/baseline ratio scores 1.0
    */
    'growth_reference' => (float) env('MEMECOIN_MONTHLY_GROWTH_REFERENCE', 20.0),
    'expansion_reference' => (float) env('MEMECOIN_MONTHLY_EXPANSION_REFERENCE', 25.0),

    /*
    | Activity is SUPPORTING EVIDENCE ONLY (15% of the score). Each input is
    | normalized min(1, ln(1 + median_value) / ln(1 + reference)) over the
    | month's eligible snapshots, then combined by the sub-weights.
    */
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
    | Chain buckets (Step 22, corrected)
    |--------------------------------------------------------------------------
    |
    | For EVERY calendar month, the top-1 performing memecoin inside each of the
    | FIVE fixed display buckets: solana, robinhood, bsc, base, other. So the
    | unique key is (year, month, chain_bucket) — at most 12 x 5 = 60 champions
    | a year. There is NO global monthly winner. The token keeps its real
    | `chain_id`; only `monthly_rankings.chain_bucket` says "other". The bucket
    | list is fixed in App\Services\Ranking\ChainBucket and is intentionally not
    | env-configurable.
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
