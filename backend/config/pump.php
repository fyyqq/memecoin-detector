<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pump Event Detection (Step 16A) — deterministic, heuristic MVP
    |--------------------------------------------------------------------------
    |
    | This layer answers "WHEN did this coin experience a significant pump?"
    | over OUR observation series. It does NOT answer "why" (16B/16C).
    |
    | Snapshots are periodic detector observations (~10 min apart), NOT
    | tick-level trades. Everything here is an "observed pump".
    |
    | The thresholds below are INITIAL HEURISTIC DEFAULTS chosen to be
    | conservative and easy to tune — they are NOT claimed to be scientifically
    | or statistically optimal. Adjust via env as real data accumulates.
    |
    */

    'thresholds' => [
        // A "significant upward move" — the trigger. At least one of market-cap
        // OR price must clear its threshold, PLUS enough total confirmations.
        'minimum_market_cap_change_pct' => (float) env('PUMP_MIN_MARKET_CAP_CHANGE_PCT', 50),
        'minimum_price_change_pct' => (float) env('PUMP_MIN_PRICE_CHANGE_PCT', 40),

        // Rolling-24h ratio thresholds (see the note below — these are NOT
        // interval volume / transaction growth).
        'minimum_volume_change_ratio' => (float) env('PUMP_MIN_VOLUME_CHANGE_RATIO', 2.0),
        'minimum_transaction_change_ratio' => (float) env('PUMP_MIN_TRANSACTION_CHANGE_RATIO', 2.0),

        // Total qualifying signals (of the 4) required to record an event. With
        // the default 2: a move + one more signal. Prevents "price moved 50% on
        // one strange observation" from becoming a pump on its own.
        'minimum_confirmation_signals' => (int) env('PUMP_MIN_CONFIRMATION_SIGNALS', 2),

        // A "strong" move (used for HIGH confidence) = this multiple of the
        // move threshold.
        'strong_move_multiplier' => (float) env('PUMP_STRONG_MOVE_MULTIPLIER', 1.5),
    ],

    'windows' => [
        // Primary comparison: latest observation vs the observation closest to
        // ~this many minutes earlier.
        'primary_minutes' => (int) env('PUMP_PRIMARY_WINDOW_MINUTES', 60),

        // Shorter "acceleration" comparison (latest vs ~this many minutes ago),
        // used only as a modest score bonus for rapid recent moves.
        'acceleration_minutes' => (int) env('PUMP_ACCELERATION_WINDOW_MINUTES', 25),

        // Because snapshots are ~10 min apart, accept the closest observation
        // within +/- this tolerance of the target window rather than an exact ts.
        'tolerance_minutes' => (int) env('PUMP_WINDOW_TOLERANCE_MINUTES', 20),
    ],

    // A new detected movement that overlaps an existing event's
    // [started_at .. ended_at|peak_at] + this window is merged into it, so one
    // continuous pump is a single event.
    'event_merge_window_minutes' => (int) env('PUMP_EVENT_MERGE_WINDOW_MINUTES', 60),

    // An `active` event whose peak observation is older than this and which is
    // no longer being detected is swept to `completed`.
    'event_stale_after_minutes' => (int) env('PUMP_EVENT_STALE_AFTER_MINUTES', 90),

    'query' => [
        // Only analyse tokens observed within (primary_minutes + tolerance +
        // this) minutes — a pump we could detect must involve recent snapshots.
        'recent_token_minutes' => (int) env('PUMP_RECENT_TOKEN_MINUTES', 30),
        // Most-recent snapshots loaded per token (bounded — never the full
        // history). ~24 = ~4h at a 10-min cadence.
        'recent_snapshots_per_token' => (int) env('PUMP_RECENT_SNAPSHOTS_PER_TOKEN', 24),
        // Minimum snapshots required to even attempt detection.
        'minimum_snapshots' => (int) env('PUMP_MINIMUM_SNAPSHOTS', 3),
    ],

    /*
    | Deterministic 0-100 detection SCORE — reflects the STRENGTH of the observed
    | move, NOT any probability of future gain. Weights sum to 100; each
    | component saturates at 2x its threshold. A rapid acceleration adds a small
    | bonus (capped).
    */
    'score' => [
        'weight_market_cap' => (float) env('PUMP_SCORE_WEIGHT_MARKET_CAP', 35),
        'weight_price' => (float) env('PUMP_SCORE_WEIGHT_PRICE', 30),
        'weight_volume' => (float) env('PUMP_SCORE_WEIGHT_VOLUME', 20),
        'weight_transactions' => (float) env('PUMP_SCORE_WEIGHT_TRANSACTIONS', 15),
        'acceleration_bonus_max' => (float) env('PUMP_SCORE_ACCELERATION_BONUS_MAX', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rolling-window caveat (READ THIS)
    |--------------------------------------------------------------------------
    |
    | `MarketSnapshot.volume_h24` and `txns_h24` are ROLLING 24-HOUR metrics.
    | `volume_h24(now) / volume_h24(1h ago)` is a rolling-window comparison, NOT
    | "1-hour volume growth". The stored fields are named
    | `volume_h24_change_ratio` and `txns_h24_change_ratio` to keep this honest.
    | They are useful directional confirmation signals only.
    |
    */
];
