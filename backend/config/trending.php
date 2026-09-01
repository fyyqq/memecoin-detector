<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chain-level market views
    |--------------------------------------------------------------------------
    |
    | Config for "Top Volume by Chain" (`GET /api/memecoins/top-volume`) and
    | "Chain Market Activity" (`GET /api/memecoins/chain-activity`, materialised
    | in `daily_chain_activity` by the discovery run). Both use deduplicated
    | token-level representative-pair volume from each token's LATEST
    | MarketSnapshot, behind the shared market-integrity gate. This is REPORTED
    | volume — never claimed to be organic / real human volume.
    |
    | (The near-real-time Trending Tracking feature that once owned this file was
    | removed; `MarketIntegrityGate` + `ChainActivityRollup` are all that remain
    | of the `App\Services\Trending` namespace.)
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
    | A token's risk scan can be older than its 6h cooldown. Older than this many
    | hours => `/top-volume` marks the row "risk_check_stale" and it is NOT
    | silently treated as safe.
    */
    'risk_stale_hours' => max(1, (int) env('MEMECOIN_RISK_SCAN_COOLDOWN_HOURS', 6)),

];
