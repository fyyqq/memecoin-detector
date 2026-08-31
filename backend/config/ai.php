<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI pump explanation (Step 16C)
    |--------------------------------------------------------------------------
    |
    | The LLM is an INTERPRETER, not a data source. It only ever sees a single
    | PumpEvent plus the ranked Evidence records our own database collected for
    | it, and it must ground every factual claim in those evidence IDs. It never
    | browses, never invents facts, never treats temporal correlation as
    | causation. See docs/pump-explanation.md.
    |
    | The concrete provider is swappable and chosen by AI_PROVIDER — nothing in
    | PumpExplanationService names a vendor. API keys stay server-side.
    |
    */

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'model' => env('AI_MODEL', 'claude-sonnet-5'),

    'timeout' => (int) env('AI_TIMEOUT', 45),

    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 1_500),

    // Interpretation should be stable, not creative.
    'temperature' => (float) env('AI_TEMPERATURE', 0.0),

    /*
    | Provider adapters. Only the one named by `provider` is constructed.
    | Credentials are read here and never leave the server.
    */
    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        // A deterministic, offline provider. Never calls the network — it
        // refuses every request so the run is recorded as `failed` rather than
        // silently fabricating an explanation. Useful for local dev without a
        // key and as the safe default when AI_PROVIDER is unset/unknown.
        'null' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Explanation run controls
    |--------------------------------------------------------------------------
    */
    'explanation' => [

        // Only explain events whose peak is within this many hours.
        'recent_event_hours' => (int) env('PUMP_EXPLANATION_RECENT_EVENT_HOURS', 48),

        // Don't regenerate a completed explanation more often than this
        // (unless --force). Evidence changes over time, so explanations are
        // never permanently frozen — just rate-limited.
        'cooldown_hours' => (int) env('PUMP_EXPLANATION_COOLDOWN_HOURS', 6),

        // Hard ceiling on events (and therefore AI calls) per command run.
        'max_events_per_run' => (int) env('PUMP_EXPLANATION_MAX_EVENTS_PER_RUN', 15),

        // Only the highest-relevance evidence records are sent to the model.
        'max_evidence' => (int) env('PUMP_EXPLANATION_MAX_EVIDENCE', 20),
    ],
];
