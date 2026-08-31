<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evidence Collection (Step 16B)
    |--------------------------------------------------------------------------
    |
    | Collects timestamped FACTS present around a detected PumpEvent — it does
    | NOT interpret them and NEVER claims causality from temporal correlation
    | (AI explanation is Step 16C). Evidence is stored separately from any
    | interpretation.
    |
    */

    // Investigation window around each event:
    //   investigation_start = pump_event.started_at - before_minutes
    //   investigation_end   = pump_event.peak_at    + after_minutes
    'window' => [
        'before_minutes' => (int) env('EVIDENCE_WINDOW_BEFORE_MINUTES', 60),
        'after_minutes' => (int) env('EVIDENCE_WINDOW_AFTER_MINUTES', 30),
    ],

    // Don't re-investigate an event more often than this. Historical evidence
    // always stays persisted; a re-run only refreshes (upserts).
    'collection_cooldown_hours' => (int) env('EVIDENCE_COLLECTION_COOLDOWN_HOURS', 2),

    // Only investigate pump events whose peak is within this many hours.
    'recent_event_hours' => (int) env('EVIDENCE_RECENT_EVENT_HOURS', 48),

    // Hard ceiling on events processed per command run.
    'max_events_per_run' => (int) env('EVIDENCE_MAX_EVENTS_PER_RUN', 20),

    /*
    | RELATED_TOKEN collector — PostgreSQL only, no HTTP. Finds OTHER tracked
    | tokens that moved strongly in the window BEFORE this event started. This is
    | NOT the future TokenRelation graph; it records neutral temporal facts only.
    */
    'related' => [
        'lead_window_minutes' => (int) env('EVIDENCE_RELATED_LEAD_WINDOW_MINUTES', 60),
        'minimum_move_pct' => (float) env('EVIDENCE_RELATED_MIN_MOVE_PCT', 40),
        'max_related' => (int) env('EVIDENCE_RELATED_MAX', 5),
        // Same chain only by default; other chains are noisier temporal matches.
        'cross_chain' => (bool) env('EVIDENCE_RELATED_CROSS_CHAIN', false),
    ],

    /*
    | NEWS collector — the ONLY collector that makes external requests.
    | GDELT 2.1 DOC API (free, no key). Treated as a discovery/evidence source,
    | never as proof of causality. Any provider failure is logged and skipped —
    | it never fails the command.
    */
    'news' => [
        'enabled' => (bool) env('EVIDENCE_NEWS_ENABLED', true),
        'provider' => env('EVIDENCE_NEWS_PROVIDER', 'gdelt'),   // gdelt | none
        'gdelt_base_url' => rtrim((string) env('EVIDENCE_GDELT_BASE_URL', 'https://api.gdeltproject.org/api/v2/doc/doc'), '/'),
        'timeout' => (int) env('EVIDENCE_NEWS_TIMEOUT', 8),
        'connect_timeout' => (int) env('EVIDENCE_NEWS_CONNECT_TIMEOUT', 4),
        // Per command-run request ceiling (shared across all events).
        'max_requests_per_run' => (int) env('EVIDENCE_NEWS_MAX_REQUESTS_PER_RUN', 15),
        // Per event result ceiling.
        'max_results_per_event' => (int) env('EVIDENCE_NEWS_MAX_RESULTS_PER_EVENT', 10),
        // Don't build a news query for a token whose name is generic AND whose
        // symbol is shorter than this (ticker-collision guard).
        'minimum_symbol_length' => (int) env('EVIDENCE_NEWS_MIN_SYMBOL_LENGTH', 4),
        // Reputable crypto-news domains — a small score/confidence nudge only.
        'trusted_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'EVIDENCE_NEWS_TRUSTED_DOMAINS',
            'coindesk.com,cointelegraph.com,theblock.co,decrypt.co,blockworks.co,dlnews.com,cryptoslate.com,beincrypto.com,bitcoinmagazine.com',
        ))))),
    ],
];
