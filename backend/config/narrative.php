<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Token Narrative Intelligence (Step 21)
    |--------------------------------------------------------------------------
    |
    | Two token-level questions, kept separate from the event-level pump
    | explanation (Step 16C):
    |
    |   1. Why was this coin created?     (origin)
    |   2. Why did this coin become popular?  (popularity)
    |
    | The AI is an INTERPRETER of collected sources + our own stored evidence.
    | It never browses, never invents sources / URLs / dates, never claims
    | unsupported creator intent, and never treats market timing as proof of
    | causation. Every factual statement cites source IDs. See
    | docs/token-narrative-intelligence.md.
    |
    */

    /*
    | Research-source providers (the abstraction that FINDS material). Ordered;
    | each runs independently and a failure of one never fails the report.
    | `internal` (our own Evidence + market history) is always available and
    | should stay first. Add `gdelt` etc. as they become reachable.
    */
    'research_providers' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('NARRATIVE_RESEARCH_PROVIDERS', 'internal,gdelt'),
    )))),

    'providers' => [

        // Always on. PostgreSQL only — the token's stored Evidence rows, token
        // metadata links, pump events and $5M-crossing events.
        'internal' => [
            'enabled' => true,
        ],

        // GDELT 2.1 DOC API — free, no key. Token-level news search over a broad
        // window. Any failure => zero web sources, logged, report continues on
        // internal evidence only. (Currently unreachable in the dev network.)
        'gdelt' => [
            'enabled' => filter_var(env('NARRATIVE_GDELT_ENABLED', true), FILTER_VALIDATE_BOOL),
            'base_url' => rtrim((string) env('NARRATIVE_GDELT_BASE_URL', 'https://api.gdeltproject.org/api/v2/doc/doc'), '/'),
            'timeout' => (int) env('NARRATIVE_GDELT_TIMEOUT', 8),
            'connect_timeout' => (int) env('NARRATIVE_GDELT_CONNECT_TIMEOUT', 4),
            'max_requests_per_run' => (int) env('NARRATIVE_GDELT_MAX_REQUESTS_PER_RUN', 20),
            'max_results_per_query' => (int) env('NARRATIVE_GDELT_MAX_RESULTS_PER_QUERY', 15),
            // Look this many days before the earliest pool creation for origin
            // context, and cover everything up to now for popularity.
            'lookback_days' => (int) env('NARRATIVE_GDELT_LOOKBACK_DAYS', 120),
        ],
    ],

    /*
    | Reputable outlets — a source-quality nudge only (news source_type -> HIGH
    | tier instead of MEDIUM). Never lets many low-quality reposts outweigh one
    | strong primary source.
    */
    'trusted_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NARRATIVE_TRUSTED_DOMAINS',
        'coindesk.com,cointelegraph.com,theblock.co,decrypt.co,blockworks.co,dlnews.com,cryptoslate.com,'
        .'beincrypto.com,bitcoinmagazine.com,reuters.com,bloomberg.com,apnews.com,theverge.com,wired.com,'
        .'knowyourmeme.com,en.wikipedia.org',
    ))))),

    // Well-established reference sites (meme / culture provenance) — HIGH tier.
    'reference_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NARRATIVE_REFERENCE_DOMAINS',
        'knowyourmeme.com,en.wikipedia.org,wikipedia.org,dictionary.com,merriam-webster.com',
    ))))),

    /*
    | AI synthesis provider. Reuses the config/ai.php credential + the same
    | provider-abstraction pattern — but is a SEPARATE binding so narrative
    | research is never coupled to one vendor or to PumpExplanationService.
    */
    'ai' => [
        'provider' => env('NARRATIVE_AI_PROVIDER', env('AI_PROVIDER', 'anthropic')),
        'model' => env('NARRATIVE_AI_MODEL', env('AI_MODEL', 'claude-sonnet-5')),
        'timeout' => (int) env('NARRATIVE_AI_TIMEOUT', 60),
        'connect_timeout' => (int) env('NARRATIVE_AI_CONNECT_TIMEOUT', 10),
        'max_tokens' => (int) env('NARRATIVE_AI_MAX_TOKENS', 3_000),
        'temperature' => (float) env('NARRATIVE_AI_TEMPERATURE', 0.0),
        // Credentials come from config/ai.php (server-side only, never exposed
        // to React).
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],
    ],

    /*
    | Run controls. Narrative research is slower + externally dependent — it runs
    | hourly, not every 10 minutes, and only re-researches a token after the
    | cooldown.
    */
    'research' => [
        'cooldown_hours' => max(1, (int) env('TOKEN_NARRATIVE_RESEARCH_COOLDOWN_HOURS', 24)),
        'max_tokens_per_run' => max(1, (int) env('TOKEN_NARRATIVE_MAX_TOKENS_PER_RUN', 10)),
        // Provider (web) response cache.
        'provider_cache_hours' => max(1, (int) env('NARRATIVE_PROVIDER_CACHE_HOURS', 6)),
        // Sources kept per section after ranking (fed to the model).
        'max_sources_per_section' => max(1, (int) env('NARRATIVE_MAX_SOURCES_PER_SECTION', 12)),
        // A token needs at least this many usable sources in a section before we
        // spend an AI call trying to synthesise that section.
        'min_sources_per_section' => max(0, (int) env('NARRATIVE_MIN_SOURCES_PER_SECTION', 1)),
    ],
];
