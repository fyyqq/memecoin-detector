<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Near-real-time Trending Tracking
    |--------------------------------------------------------------------------
    |
    | The detector's primary concept: "what is trending on DexScreener right now"
    | for the 6H and 24H timeframes, across all chains, built ONLY on the
    | documented `GET /metas/trending/v1` -> `GET /metas/meta/v1/{slug}` APIs.
    |
    | DexScreener's real proprietary per-pair `trendingScoreH6/H24` is delivered
    | over an undocumented, Cloudflare-bot-walled, binary WebSocket
    | (io.dexscreener.com) and is deliberately NOT used — see
    | docs/trending-discovery-reconnaissance.md. Our `tracked_trend_score` is a
    | transparent, deterministic INTERNAL ranking. It is NEVER presented as
    | DexScreener's score. Wording: "Tracked Trending" / "Trending by DexScreener
    | market signals". Every stored record carries `source = dexscreener_meta`.
    |
    | See docs/trending-tracking.md.
    |
    */

    // The scheduler refreshes trending candidates every this-many minutes.
    // "Near real-time" / "Updated every ~5 minutes" — NOT tick-level real-time.
    'refresh_minutes' => max(1, min(60, (int) env('MEMECOIN_TREND_REFRESH_MINUTES', 5))),

    // Timeframes we track. The 6H view weights recency/persistence a little
    // harder; the 24H view is the steadier picture. Both use the same formula.
    'timeframes' => ['6h', '24h'],

    /*
    |--------------------------------------------------------------------------
    | "Trending Now" — TOP N of the ELIGIBLE current trending memecoins
    |--------------------------------------------------------------------------
    |
    | The homepage does NOT show the whole trending candidate universe. It shows
    | only the top newly-launched memecoins that pass the hard filters below.
    | `GET /api/memecoins/trending` returns `top_n` rows by default and never
    | more than `top_max` (no pagination — the result is intentionally small).
    */
    'top_n' => max(1, (int) env('MEMECOIN_TREND_TOP_N', 10)),
    'top_max' => max(1, (int) env('MEMECOIN_TREND_TOP_MAX', 20)),

    /*
    | Trending-Now ELIGIBILITY — HARD filters applied BEFORE the final public
    | ranking (at collection) and re-checked at read time. These narrow "Trending
    | Now" ONLY. They do NOT replace / touch the MAIN LIST qualification
    | (observed/verified PEAK in [$5M, $200M]), `observed_peak_market_cap`,
    | `historical_peak_value`, `qualification_events` or the risk logic.
    |
    |   is_memecoin_candidate == TRUE   (MemecoinClassifier)
    |   AND earliest_pair_created_at known AND age <= max_age_days
    |   AND CURRENT market_cap in [min_current_market_cap, max_current_market_cap]
    |   AND liquidity_usd > 0
    |   AND volume > 0  (per timeframe: h6 for the 6H view, h24 for the 24H view)
    |
    | The band values reuse the discovery config so the two stay in lock-step.
    */
    'eligibility' => [
        'max_age_days' => (int) env('MEMECOIN_MAX_AGE_DAYS', 30),
        'min_current_market_cap' => (float) env('MEMECOIN_OBSERVED_PEAK_MIN_USD', 5_000_000),
        'max_current_market_cap' => (float) env('MEMECOIN_OBSERVED_PEAK_MAX_USD', 200_000_000),
        // Loose single-pair age pre-gate (on the meta pair's pairCreatedAt) used
        // ONLY to decide which new tokens to enrich; the strict gate uses the
        // real `earliest_pair_created_at` from the Token model.
        'enrich_prefilter_max_age_days' => max(1, (int) env('MEMECOIN_TREND_ENRICH_PREFILTER_AGE_DAYS', 35)),
    ],

    /*
    | Memecoin classification (MemecoinClassifier). Not a naive keyword-only
    | definition — a strong meme signal is meme-narrative trending-meta
    | membership OR a meme keyword; a non-meme signal is a stablecoin / wrapped /
    | liquid-staking / infra / governance / blue-chip identity.
    */
    'memecoin' => [
        // Symbols that are definitely NOT memecoins -> FALSE.
        'deny_symbols' => array_filter(array_map('trim', explode(',', (string) env('MEMECOIN_TREND_DENY_SYMBOLS',
            'usdt,usdc,dai,busd,tusd,usde,usds,fdusd,frax,lusd,gusd,pyusd,usdd,eurc,usdy,'
            .'btc,eth,sol,bnb,xrp,ada,avax,dot,matic,pol,link,ltc,bch,trx,ton,near,atom,'
            .'uni,aave,mkr,comp,crv,snx,ldo,arb,op,sui,apt,sei,tia,inj,rune,'
            .'steth,wsteth,reth,cbeth,weeth,ezeth,rseth,sfrxeth,meth,ankreth,'
            .'wbtc,cbbtc,tbtc,lbtc,solvbtc,jlp,jitosol,msol,bsol,jupsol')))),
        // Name substrings that indicate infra / utility / governance / LST.
        'deny_name_patterns' => array_filter(array_map('trim', explode('|', (string) env('MEMECOIN_TREND_DENY_NAME_PATTERNS',
            'staked ether|staked eth|liquid staking|liquid staked|restaked|restaking|'
            .'wrapped |governance token|lending protocol|oracle network|bridged |'
            .'tether |usd coin|dai stablecoin|first digital usd|payment protocol')))),
        // Trending-meta slugs that STRONGLY indicate a meme narrative -> TRUE.
        'meme_meta_slugs' => array_filter(array_map('trim', explode(',', (string) env('MEMECOIN_TREND_MEME_META_SLUGS',
            'dog,cat,frog,pepe,animal,animals,internet-animals,meme,memes,meme-hall-of-fame,'
            .'character,characters,degen,trump,elon,elon-musk,celebrity,celebrities,'
            .'brainrot,slang,tiktok,chinese,knockoff-legends,knockoff,dog-coins,cat-coins,'
            .'politifi,politics,wojak,anime,waifu,couch,mascot')))),
        // Slugs that are utility-ish — membership alone is NOT a meme signal.
        'utility_meta_slugs' => array_filter(array_map('trim', explode(',', (string) env('MEMECOIN_TREND_UTILITY_META_SLUGS',
            'ai,defi,depin,rwa,nft,x402,stonks,infrastructure,gaming,layer-1,layer-2')))),
        // Meme keywords in the name/symbol -> TRUE.
        'meme_keywords' => array_filter(array_map('trim', explode(',', (string) env('MEMECOIN_TREND_MEME_KEYWORDS',
            'pepe,doge,shib,shiba,inu,wif,bonk,floki,brett,mog,popcat,pnut,cat,dog,frog,'
            .'moon,elon,chad,wojak,giga,turbo,degen,meme,baby,trump,maga,kitty,puppy,'
            .'hippo,goat,penguin,capybara,duck,bear,bull,ape,monkey,fart,based,ser,fren,anon,lambo')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | tracked_trend_score — transparent deterministic 0-100 internal ranking
    |--------------------------------------------------------------------------
    |
    | score = 100 * ( Σ weight_i * component_i ) / Σ weight_i
    |
    | Components (each normalised + clamped to 0..1):
    |   momentum              tanh-shaped from price_change_pct for the timeframe
    |   volume_activity       capped-log from volume_usd for the timeframe
    |   transaction_activity  saturating from transaction_count for the timeframe
    |   liquidity_quality     saturating from liquidity_usd (deeper = steadier)
    |   persistence           how many of the recent captures this token trended
    |
    | MARKET CAP IS NOT A COMPONENT — a big token does not out-rank a smaller one
    | just for being big.
    |
    | A component whose metric is missing/unusable gets `unavailable_component`
    | (a reduced value, below the 0.5 midpoint) — incomplete data lowers the
    | score, it never inflates it.
    */
    'score' => [
        'weights' => [
            'momentum' => (float) env('MEMECOIN_TREND_W_MOMENTUM', 0.30),
            'volume_activity' => (float) env('MEMECOIN_TREND_W_VOLUME', 0.28),
            'transaction_activity' => (float) env('MEMECOIN_TREND_W_TXNS', 0.18),
            'liquidity_quality' => (float) env('MEMECOIN_TREND_W_LIQUIDITY', 0.12),
            'persistence' => (float) env('MEMECOIN_TREND_W_PERSISTENCE', 0.12),
        ],

        // Normalisation anchors (the raw value at which a component reaches ~0.5,
        // except momentum which is tanh-centred on 0).
        'references' => [
            // momentum: 0.5 * (1 + tanh(price_change_pct / ref)). +ref% -> ~0.88.
            'momentum_pct' => (float) env('MEMECOIN_TREND_REF_MOMENTUM_PCT', 60.0),
            // volume_activity: ln(1 + v) / ln(1 + ref). ref USD -> 1.0.
            'volume_usd' => (float) env('MEMECOIN_TREND_REF_VOLUME_USD', 2_000_000.0),
            // transaction_activity: t / (t + ref). ref txns -> 0.5.
            'txns' => (float) env('MEMECOIN_TREND_REF_TXNS', 800.0),
            // liquidity_quality: l / (l + ref). ref USD -> 0.5.
            'liquidity_usd' => (float) env('MEMECOIN_TREND_REF_LIQUIDITY_USD', 150_000.0),
        ],

        'unavailable_component' => (float) env('MEMECOIN_TREND_UNAVAILABLE_COMPONENT', 0.25),
    ],

    /*
    | Persistence window. We look back over this many 5-minute captures (12 ≈ 1h)
    | and the persistence component is (times this token trended) / window.
    | Also the number of recent captures the read APIs and the discovery
    | prioritizer consider "recent".
    */
    'persistence' => [
        'window_captures' => max(1, (int) env('MEMECOIN_TREND_PERSISTENCE_WINDOW', 12)),
    ],

    /*
    | Collection controls. `/metas/*` is on the 60/min bucket; `/token-pairs/v1`
    | on the 300/min bucket. The collector reuses the DexScreenerClient's 60s
    | response cache + bounded concurrency.
    */
    'collect' => [
        // Reuse the discovery limit — how many /metas/trending/v1 entries to expand.
        'max_metas' => max(0, (int) env('DEXSCREENER_TRENDING_META_LIMIT', 18)),
        // A trending token we do not yet track is enriched to a Token via
        // /token-pairs/v1 — capped per run so a burst never abuses the provider.
        'max_new_token_enrich' => max(0, (int) env('MEMECOIN_TREND_MAX_NEW_TOKEN_ENRICH', 40)),
        // Loose single-pair age pre-gate for the enrich step (days). The strict
        // gate still uses min(pairCreatedAt) across all pairs.
        'enrich_prefilter_max_age_days' => max(1, (int) env('MEMECOIN_TREND_ENRICH_PREFILTER_AGE_DAYS', 35)),
        // Hard ceiling on ELIGIBLE trending memecoins scored + stored per
        // timeframe per run. Small on purpose — the API only ever returns
        // top_n (10) / top_max (20); this is headroom for the chain filter and
        // for "Trending History".
        'max_candidates_per_timeframe' => max(1, (int) env('MEMECOIN_TREND_MAX_CANDIDATES', 60)),
    ],

    /*
    | Retention. Trend snapshots are the large table — pruned aggressively. The
    | daily rollups are small and kept long so "what was trending last month"
    | still works.
    */
    'retention' => [
        'snapshot_days' => max(1, (int) env('MEMECOIN_TREND_SNAPSHOT_RETENTION_DAYS', 30)),
        'daily_days' => max(1, (int) env('MEMECOIN_DAILY_TREND_RETENTION_DAYS', 365)),
    ],

    /*
    | "Top Volume by Chain" + "Chain Market Activity" — deduplicated token-level
    | representative-pair volume from each token's LATEST MarketSnapshot, behind
    | a market-integrity gate. This is REPORTED volume, never claimed organic.
    */
    'volume' => [
        'top_per_chain' => max(1, (int) env('MEMECOIN_TOP_VOLUME_PER_CHAIN', 5)),
        // A token with no snapshot newer than this many hours is not "active".
        'active_within_hours' => max(1, (int) env('MEMECOIN_CHAIN_ACTIVITY_ACTIVE_HOURS', 48)),
    ],

    /*
    | Market-integrity gate (shared). Removes obvious anomalies BEFORE ranking by
    | volume. It does NOT certify the remaining volume as organic / real human
    | volume — no free provider gives us that.
    */
    'integrity' => [
        'min_liquidity_usd' => (float) env('MEMECOIN_INTEGRITY_MIN_LIQUIDITY_USD', 1.0),
        'min_transaction_count' => max(0, (int) env('MEMECOIN_INTEGRITY_MIN_TXNS', 1)),
        // A market cap above this is a garbage provider record, not a real cap.
        'max_market_cap_usd' => (float) env('MEMECOIN_INTEGRITY_MAX_MC_USD', 1_000_000_000_000.0),
        // volume_usd / liquidity_usd above this is an extreme wash-trade shape.
        'max_volume_liquidity_ratio' => (float) env('MEMECOIN_INTEGRITY_MAX_VOL_LIQ_RATIO', 75.0),
    ],

    /*
    | A trending token's risk scan can be stale — trending does not refresh it.
    | Older than this many hours => the UI shows "RISK CHECK STALE" and the row
    | is NOT silently treated as safe. Reuses the risk scan cooldown.
    */
    'risk_stale_hours' => max(1, (int) env('MEMECOIN_RISK_SCAN_COOLDOWN_HOURS', 6)),

];
