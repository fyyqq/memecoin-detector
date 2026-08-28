<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 30-Day Trend Score
    |--------------------------------------------------------------------------
    |
    | A transparent, deterministic 0-100 score built ONLY from data we already
    | store (the token's peak state + its latest MarketSnapshot). No AI, no
    | social signals, no ML. Full formula: docs/sprint-1-discovery.md.
    |
    | trend_score = round( sum(weight_i * component_i) / sum(weight_i) , 1 )
    |
    */

    'weights' => [
        'price_momentum' => (float) env('TREND_WEIGHT_PRICE_MOMENTUM', 0.30),
        'volume_liquidity' => (float) env('TREND_WEIGHT_VOLUME_LIQUIDITY', 0.30),
        'peak_retention' => (float) env('TREND_WEIGHT_PEAK_RETENTION', 0.20),
        'transaction_activity' => (float) env('TREND_WEIGHT_TRANSACTION_ACTIVITY', 0.20),
    ],

    /*
    | Reference points for the normalisation curves. Each is the raw value at
    | which its component reaches its "half" score — chosen to be meaningful,
    | not arbitrary. See the doc for the exact curves.
    */

    // price_momentum: 50 * (1 + tanh(price_change_h24 / reference_pct)).
    // A +100% 24h move scores ~88; -100% scores ~12; 0% scores 50.
    'momentum_reference_pct' => (float) env('TREND_MOMENTUM_REFERENCE_PCT', 100.0),

    // volume_liquidity: ratio = volume_h24 / liquidity_usd;
    // score = 100 * ratio / (ratio + reference_ratio).
    // ratio 1 (a day's volume == liquidity) scores 50; ratio 3 scores 75.
    'volume_liquidity_reference_ratio' => (float) env('TREND_VOLUME_LIQUIDITY_REFERENCE_RATIO', 1.0),

    // transaction_activity: score = 100 * txns_h24 / (txns_h24 + reference_count).
    // 500 txns/24h scores 50; 1500 scores 75.
    'txns_reference_count' => (float) env('TREND_TXNS_REFERENCE_COUNT', 500.0),

    /*
    | Score assigned to a component whose underlying metric is missing/unusable
    | (e.g. null liquidity, null market cap). Deliberately "reduced" (below the
    | 50 midpoint) so incomplete data lowers the overall score rather than
    | inflating it.
    */
    'unavailable_component_score' => (float) env('TREND_UNAVAILABLE_COMPONENT_SCORE', 25.0),

    /*
    | Safety ceiling: how many qualified tokens to pull + score in PHP before
    | sorting by trend. Only the latest snapshot per token is loaded (no history).
    */
    'scan_limit' => (int) env('TREND_SCAN_LIMIT', 200),

];
