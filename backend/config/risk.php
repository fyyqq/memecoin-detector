<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Memecoin Risk & Safety Screening (Step 24)
    |--------------------------------------------------------------------------
    |
    | A conservative risk-screening layer that sits ON TOP OF the existing
    | market-cap qualification (age <= 30d AND a verified/observed peak in
    | [$5M, $200M]). It NEVER changes that qualification — it only routes an
    | already-qualified token between:
    |
    |   MAIN LIST   — LOWER / MEDIUM risk, screened, mature
    |   RISK WATCH  — HIGH / CRITICAL / UNKNOWN risk, or too young, or
    |                 insufficient security data (visible, flagged, never hidden)
    |
    | This is a RISK FILTER, NOT a "safe to invest" guarantee. Terminology is
    | LOWER / MEDIUM / HIGH RISK, CRITICAL / AVOID, RISK UNKNOWN — never "safe",
    | "guaranteed", or "scam probability". Scoring is deterministic; no AI.
    |
    | See docs/risk-screening.md and docs/memecoin-risk-reconnaissance.md.
    |
    */

    /*
    | MAIN-LIST maturity + screening gate.
    */
    'main_list' => [
        // Tokens younger than this cannot enter the MAIN LIST. They may still
        // appear in RISK WATCH if they are market-cap qualified. This is a
        // risk-management rule, NOT a claim that every young token is bad.
        'min_age_hours' => max(0, (int) env('MEMECOIN_MAIN_MIN_AGE_HOURS', 72)),

        // When true, a market-qualified token only reaches the MAIN LIST if it
        // has a completed risk assessment whose level is LOWER or MEDIUM and
        // which tripped no hard filter. A token with no assessment yet, or a
        // partial/failed one, is routed to RISK WATCH ("risk unknown").
        'require_screening' => filter_var(env('MEMECOIN_RISK_MAIN_LIST_REQUIRE_SCREENING', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Data completeness. If fewer than this fraction of the applicable signals
    | could actually be measured, the assessment is RISK UNKNOWN (distinct from
    | HIGH RISK) and the token cannot enter the MAIN LIST.
    */
    'min_data_completeness' => (float) env('MEMECOIN_RISK_MIN_DATA_COMPLETENESS', 0.50),

    /*
    | Deterministic 0-100 risk score. Higher = MORE risk. This is a heuristic
    | risk-screening score — it is NOT a probability of scam / rug / loss.
    | Dimension weights sum to 1.0.
    */
    'score' => [
        'weights' => [
            'contract_security' => (float) env('MEMECOIN_RISK_W_CONTRACT', 0.30),
            'exit_safety' => (float) env('MEMECOIN_RISK_W_EXIT_SAFETY', 0.15),
            'holder_distribution' => (float) env('MEMECOIN_RISK_W_HOLDERS', 0.18),
            'liquidity' => (float) env('MEMECOIN_RISK_W_LIQUIDITY', 0.12),
            'pump_dump' => (float) env('MEMECOIN_RISK_W_PUMP_DUMP', 0.12),
            'market_structure' => (float) env('MEMECOIN_RISK_W_MARKET_STRUCTURE', 0.08),
            'age' => (float) env('MEMECOIN_RISK_W_AGE', 0.05),
        ],

        // Score -> level band edges (higher score = more risk). A hard override
        // (below) can raise a token to HIGH / CRITICAL regardless of score.
        'levels' => [
            'medium_at' => (int) env('MEMECOIN_RISK_LEVEL_MEDIUM_AT', 25),
            'high_at' => (int) env('MEMECOIN_RISK_LEVEL_HIGH_AT', 50),
            'critical_at' => (int) env('MEMECOIN_RISK_LEVEL_CRITICAL_AT', 75),
        ],
    ],

    /*
    | Contract-security thresholds + hard filters.
    |
    | TRI-STATE: every signal is MEASURED (a real value), BAD (a measured
    | dangerous value), or UNKNOWN (null / "" / missing / unsupported chain).
    | UNKNOWN never becomes a positive or a negative — it contributes 0 to the
    | score. The SINGLE documented exception is `is_mintable`: an explicit
    | `true` is HIGH RISK; UNKNOWN mint is NOT claimed to be mintable and is
    | scored as UNKNOWN (see docs).
    */
    'contract' => [
        // Sell tax at or above this fraction (1.0 == 100%) => CRITICAL (cannot
        // exit the position).
        'sell_tax_critical_at' => (float) env('MEMECOIN_RISK_SELL_TAX_CRITICAL_AT', 1.0),
        // Sell OR buy tax at or above this fraction => HIGH RISK.
        'tax_high_at' => (float) env('MEMECOIN_RISK_TAX_HIGH_AT', 0.10),
        // Tax bands for the soft score (fraction of supply): 2% / 5% / 10%.
        'tax_elevated_at' => (float) env('MEMECOIN_RISK_TAX_ELEVATED_AT', 0.02),
        'tax_warning_at' => (float) env('MEMECOIN_RISK_TAX_WARNING_AT', 0.05),

        // is_mintable == true => at minimum this level.
        'mintable_level' => env('MEMECOIN_RISK_MINTABLE_LEVEL', 'HIGH'),

        // Treat a live Solana freeze authority as a CRITICAL hard filter (an
        // account that can be frozen cannot be sold).
        'solana_freeze_authority_critical' => filter_var(env('MEMECOIN_RISK_SOLANA_FREEZE_CRITICAL', true), FILTER_VALIDATE_BOOL),
        // Treat a live Solana balance-mutate authority as CRITICAL.
        'solana_balance_mutable_critical' => filter_var(env('MEMECOIN_RISK_SOLANA_BALANCE_MUTABLE_CRITICAL', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Holder concentration. Effective % = after excluding burn / LP-pair /
    | known-CEX / bridge / locker addresses.
    */
    'holders' => [
        'top1_critical_at' => (float) env('MEMECOIN_RISK_TOP1_CRITICAL_AT', 0.50),
        'top1_high_at' => (float) env('MEMECOIN_RISK_TOP1_HIGH_AT', 0.35),
        'top1_warning_at' => (float) env('MEMECOIN_RISK_TOP1_WARNING_AT', 0.20),
        'creator_high_at' => (float) env('MEMECOIN_RISK_CREATOR_HIGH_AT', 0.20),
        'creator_warning_at' => (float) env('MEMECOIN_RISK_CREATOR_WARNING_AT', 0.10),
        // A holder GoPlus marks as a CONTRACT holding at least this share is
        // treated as infrastructure (AMM pool / vault / bridge), not a whale,
        // and excluded from effective concentration.
        'contract_exclude_pct' => (float) env('MEMECOIN_RISK_CONTRACT_EXCLUDE_PCT', 0.30),
        // "holders per $1M market cap" reference — below this reads as thin.
        'per_million_reference' => (float) env('MEMECOIN_RISK_HOLDERS_PER_MILLION_REF', 50.0),

        // Burn / dead / incinerator addresses excluded from concentration.
        'burn_addresses' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'MEMECOIN_RISK_BURN_ADDRESSES',
            '0x0000000000000000000000000000000000000000,'
            .'0x000000000000000000000000000000000000dEaD,'
            .'1nc1nerator11111111111111111111111111111111',
        ))))),

        // GoPlus `tag` substrings that mark a holder as infrastructure, not a
        // whale: pools, lockers, CEX custody, bridges.
        'infrastructure_tags' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'MEMECOIN_RISK_INFRA_TAGS',
            'lock,uniswap,pancake,raydium,orca,meteora,liquidity,burn,'
            .'binance,coinbase,okx,bybit,kucoin,gate.io,bridge,wormhole,'
            .'layerzero,portal,team finance,unicrypt,pinklock,pinksale',
        ))))),
    ],

    /*
    | Liquidity structure + LP safety.
    */
    'liquidity' => [
        // Minimum usable total liquidity — below this the token has effectively
        // no market and is a HARD liquidity failure.
        'min_total_usd' => (float) env('MEMECOIN_RISK_MIN_LIQUIDITY_USD', 10_000.0),
        // "Thin" liquidity — a single pool below this, with no LP-lock/burn
        // evidence, is a HARD single-point-of-failure. A single DEEP pool is
        // only a soft (medium) concern.
        'thin_total_usd' => (float) env('MEMECOIN_RISK_THIN_LIQUIDITY_USD', 50_000.0),
        // A single pool holding at least this share of total liquidity is
        // treated as effectively single-pool.
        'dominant_pool_share' => (float) env('MEMECOIN_RISK_DOMINANT_POOL_SHARE', 0.90),
        // LP locked/burned fraction that counts as "LP safety evidence".
        'lp_locked_safe_at' => (float) env('MEMECOIN_RISK_LP_LOCKED_SAFE_AT', 0.50),
        // volume/liquidity turnover bands (heuristic — NOT proof of anything).
        'turnover_bands' => [2.0, 5.0, 10.0],
    ],

    /*
    | Pump-dump shape — computed numerically from OUR market_snapshots +
    | pump_events. No chart images, no vision.
    */
    'pump_dump' => [
        // Rolling window (hours) for max run-up / max drawdown scans.
        'window_hours' => (int) env('MEMECOIN_RISK_PUMP_WINDOW_HOURS', 6),
        // A "round trip" = a run-up of at least this fraction that then retraces
        // at least `round_trip_retrace` of that gain.
        'round_trip_runup' => (float) env('MEMECOIN_RISK_ROUND_TRIP_RUNUP', 1.0),
        'round_trip_retrace' => (float) env('MEMECOIN_RISK_ROUND_TRIP_RETRACE', 0.60),
        // Peak-to-current drawdown that (with a round trip + volume collapse) is
        // a HARD pump-dump failure for the MAIN LIST.
        'crash_drawdown_at' => (float) env('MEMECOIN_RISK_CRASH_DRAWDOWN_AT', 0.70),
        // "volume collapse" = current 24h volume below this fraction of the
        // volume observed at the peak.
        'volume_collapse_at' => (float) env('MEMECOIN_RISK_VOLUME_COLLAPSE_AT', 0.20),
        // Fewer than this many snapshots => INSUFFICIENT_HISTORY, contributes 0.
        'min_snapshots' => (int) env('MEMECOIN_RISK_PUMP_MIN_SNAPSHOTS', 6),
    ],

    /*
    | Age / market-cap heuristic warning bands (soft signals — NOT proof of
    | manipulation). Each: [max_age_hours, min_market_cap_usd].
    */
    'age_market_cap_bands' => [
        [3, (int) env('MEMECOIN_RISK_BAND_3H_MC', 10_000_000)],
        [24, (int) env('MEMECOIN_RISK_BAND_24H_MC', 20_000_000)],
        [72, (int) env('MEMECOIN_RISK_BAND_72H_MC', 20_000_000)],
    ],

    /*
    | Run controls. Screening is externally dependent (GoPlus + GeckoTerminal),
    | so it runs after discovery/qualification, caps tokens per run, and never
    | rescans a token inside the cooldown.
    */
    'run' => [
        'scan_cooldown_hours' => max(0, (int) env('MEMECOIN_RISK_SCAN_COOLDOWN_HOURS', 6)),
        'max_tokens_per_run' => max(1, (int) env('MEMECOIN_RISK_MAX_TOKENS_PER_RUN', 15)),
        'provider_version' => (string) env('MEMECOIN_RISK_PROVIDER_VERSION', 'risk-2026.09-goplus+gt'),
    ],

    /*
    | GoPlus Security API — the primary security provider. Free, no key required
    | (an optional App Key raises limits). EVM `token_security/{numeric_id}` +
    | Solana `solana/token_security` (different schema). Server-side only — the
    | key is NEVER exposed to React.
    */
    'goplus' => [
        'enabled' => filter_var(env('MEMECOIN_RISK_GOPLUS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => rtrim((string) env('GOPLUS_BASE_URL', 'https://api.gopluslabs.io/api/v1'), '/'),
        'app_key' => env('GOPLUS_APP_KEY'),
        'app_secret' => env('GOPLUS_APP_SECRET'),
        'timeout' => (int) env('GOPLUS_TIMEOUT', 8),
        'connect_timeout' => (int) env('GOPLUS_CONNECT_TIMEOUT', 4),
        'retry_sleep_ms' => (int) env('GOPLUS_RETRY_SLEEP_MS', 1_000),
        'cache_ttl' => (int) env('GOPLUS_CACHE_TTL', 21_600),
        'max_calls_per_run' => (int) env('GOPLUS_MAX_CALLS_PER_RUN', 60),
    ],

    /*
    | GeckoTerminal `/info` — secondary verification (holder buckets, Solana
    | mint/freeze authority, honeypot flag). Reuses config/historical.php
    | credentials + cache. Only called where useful.
    */
    'geckoterminal' => [
        'enabled' => filter_var(env('MEMECOIN_RISK_GT_INFO_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_calls_per_run' => (int) env('MEMECOIN_RISK_GT_MAX_CALLS_PER_RUN', 30),
    ],

    /*
    | DexScreener `/token-pairs/v1` — reused for liquidity structure (pool /
    | DEX spread). The screening command may call it; the READ APIs never do.
    */
    'dexscreener' => [
        'enabled' => filter_var(env('MEMECOIN_RISK_DEXSCREENER_PAIRS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_calls_per_run' => (int) env('MEMECOIN_RISK_DEXSCREENER_MAX_CALLS_PER_RUN', 40),
    ],

    /*
    | DexScreener slug -> GoPlus chain id. EVM ids are numeric strings; Solana
    | uses the dedicated `solana/token_security` endpoint (mapped to the literal
    | "solana" here and special-cased in the client). A chain absent from this
    | map => contract security is UNKNOWN (NOT an automatic HIGH RISK).
    */
    'goplus_chain_map' => [
        'ethereum' => '1',
        'bsc' => '56',
        'base' => '8453',
        'arbitrum' => '42161',
        'polygon' => '137',
        'avalanche' => '43114',
        'optimism' => '10',
        'solana' => 'solana',
        'tron' => 'tron',
    ],
];
