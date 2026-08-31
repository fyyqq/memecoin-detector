# CLAUDE.md — Memecoin Detector

Guidance for working in this repository.

## What this project is

A lightweight **Memecoin Intelligence platform**. It detects newly launched
memecoins, identifies related-token movements, explains pump catalysts using
stored evidence, and preserves historical monthly leaders.

This is **not** the full AI Macro Intelligence Platform. This product is
intentionally smaller, faster, memecoin-specific, market-focused, and optimized
for quick daily use.

## Core principles (do not violate)

- **DexScreener is the primary live market source.** GeckoTerminal is used only
  where historical market data is required.
- **Never claim causality from correlation alone.** Two tokens moving together
  is not proof one caused the other.
- **AI explanations must be evidence-backed.** Every catalyst claim links to a
  stored `Evidence` record. AI must never invent a catalyst.
- **Never fabricate historical data.** If we did not capture it, we do not have
  it. No back-filled guesses.
- **Keep the MVP small and fast.** Prefer the smallest useful implementation.
- **Do not mix market cap and FDV silently.** Always label which one is shown
  and store both when available.
- **Do not call a token the "main coin" / leading token unless a ranking rule
  selected it.** "Main coin" is an output of a documented ranking rule, never an
  assumption.

## Technology stack

### Frontend
- React.js
- TypeScript
- Vite

### Backend
- Laravel 12
- PHP 8.4

### Database
- PostgreSQL 16

### Infrastructure
- Docker
- Docker Compose

### Development
- DBeaver (database GUI)

### Market data
- DexScreener public API — primary, live market data
- GeckoTerminal — only where historical market data (e.g. OHLCV) is required

> The DexScreener public API does **not** provide unlimited complete historical
> market data. Treat it as a live/current-state source.

## Architecture

```
Browser
  ↓
React frontend      (presentation only)
  ↓
Laravel API         (business logic, external API access, normalization)
  ↓
PostgreSQL          (persistent store, evidence, historical rankings)
```

### Architectural rules

1. React is the presentation layer.
2. Laravel owns API and business logic.
3. PostgreSQL is the persistent data store.
4. External **APIs** must be accessed from Laravel, never directly from the
   browser. (Documented exception, Step 17: the token detail page embeds a
   DexScreener price-chart `<iframe>` — a third-party *visual* embed. Our
   JavaScript still never calls `api.dexscreener.com`; only the iframe element
   loads DexScreener content.)
5. External API failures must be handled gracefully.
6. Raw provider responses are not trusted blindly — normalize them first.
7. Never expose secrets to React.
8. All external provider URLs / configuration live in environment variables.
9. Historical claims require stored evidence.
10. AI must never invent a catalyst.

## Repository structure

```
memecoin-detector/
├── backend/            Laravel 12 API (PHP 8.4)
│   ├── app/
│   ├── config/         config/cors.php is env-driven
│   ├── routes/
│   │   ├── api.php     API routes (prefix: /api), e.g. GET /api/health
│   │   └── web.php
│   └── .env.example
├── frontend/           React + TypeScript + Vite (dashboard + token detail)
│   ├── src/
│   │   ├── api/         memecoins.ts, memecoinDetail.ts (Laravel only)
│   │   ├── types/       memecoin.ts, memecoinDetail.ts
│   │   ├── lib/         format.ts ($74.6M / 8d / % / timestamps)
│   │   ├── components/  MemecoinTable, ChainFilter, CopyAddress, MarketCapSparkline, RecentlyCrossedSection, TokenNarrativeSection
│   │   ├── pages/       Dashboard.tsx, MemecoinDetail.tsx
│   │   └── App.tsx      React Router: / and /memecoin/:chainId/:tokenAddress
│   ├── vite.config.ts  host: true, port 5180
│   └── .env.example    VITE_API_URL
├── docker/
│   ├── backend/        Dockerfile + entrypoint.sh (composer install, key:generate,
│   │                   wait-for-db, migrate --isolated). Used by BOTH the
│   │                   backend and scheduler services (different command only).
│   └── frontend/       Dockerfile (npm run dev)
├── docs/
├── .mcp.json           Project MCP servers (github, notion)
├── docker-compose.yml  services: postgres, backend, scheduler, frontend
├── .env.example        Compose environment template
├── README.md
└── CLAUDE.md
```

## Development

### Start / stop

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

docker compose up -d --build     # start the FULL stack (incl. the scheduler)
docker compose logs -f backend   # watch first-boot (composer install + migrate)
docker compose logs -f scheduler # watch scheduled ingestion runs
docker compose down              # stop (keep data)
docker compose down -v           # stop + drop the PostgreSQL volume
```

`docker compose up -d` alone is enough for a complete dev environment — the
`scheduler` service runs `php artisan schedule:work` and fires
`memecoins:discover` every 10 minutes automatically. Nothing needs to be
started by hand.

### URLs / ports

| Service           | URL / host                        |
| ----------------- | --------------------------------- |
| Laravel API       | http://localhost:8010             |
| Health endpoint   | http://localhost:8010/api/health  |
| React frontend    | http://localhost:5180             |
| PostgreSQL        | localhost:5433 (→ container 5432) |
| Scheduler         | no host port (background process) |

Non-default ports are deliberate — another local project occupies 8000 / 5173 /
5432. Override via `BACKEND_PORT` / `FRONTEND_PORT` / `POSTGRES_PORT` in `.env`.

Inside the Docker network, services reach each other by name: `postgres:5432`,
`backend:8010`, `frontend:5180`.

### Common commands

```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan tinker
docker compose exec backend ./vendor/bin/pint          # code style
docker compose exec postgres createdb -U memecoin memecoin_test  # once, before tests
docker compose exec backend php artisan test           # RefreshDatabase → memecoin_test (Postgres)
docker compose exec backend php artisan memecoins:discover   # run one ingestion by hand
docker compose exec scheduler php artisan schedule:list      # inspect the schedule
docker compose exec scheduler php artisan schedule:clear-cache  # release a stuck overlap mutex
docker compose exec frontend npm run lint
docker compose exec frontend npm run build
docker compose exec postgres psql -U memecoin -d memecoin
```

## Git & Sprint Commit Policy

### Workflow

```
IMPLEMENT → TEST → VERIFY → REVIEW → COMMIT → PUSH
```

### Sprint checkpoint rule

At the end of every **explicitly completed** sprint/step:

1. Inspect `git status`.
2. Inspect `git diff`.
3. Run the relevant tests.
4. Run formatting / lint / build checks applicable to the changed code.
5. Verify the feature actually works.
6. Verify no secrets are staged.
7. Verify no unrelated files are included.
8. Stage **only** files belonging to that sprint.
9. Create a descriptive commit.
10. Push the commit to the configured GitHub remote.

The commit must represent the completed sprint as a single atomic checkpoint.

### Sprint boundary

A sprint boundary is defined by the **current user instruction** — not by every
arbitrary code change. Only trigger the commit/push workflow when **either**:

- **A.** the user has explicitly completed the current sprint/step, **or**
- **B.** the user asks to finalize / commit the current sprint.

Completion signals: "Sprint 8 complete", "Step 10 complete", "done, commit it",
"finish this sprint".

When the user's message explicitly declares the sprint/step complete, do **not**
ask for confirmation to commit — unless there is a safety, verification, or scope
issue. Otherwise, do not commit.

### Before commit — always run

```bash
git status
git diff --stat
git diff --check
```

Then stage specific files and re-inspect:

```bash
git add <specific-files>
git diff --cached --stat
git diff --cached --check
```

Never use `git add .` unless the entire working tree is confirmed to contain
only changes from the current sprint.

### Commit naming (Conventional Commits)

Prefer the scope of the actual sprint over generic messages.

```
feat: add DexScreener discovery pipeline
feat: add market snapshot persistence
feat: add scheduled memecoin ingestion
feat: add 30-day memecoin dashboard
fix: correct observed peak qualification
refactor: simplify discovery service
docs: update Sprint 1 architecture
```

The final message must describe the actual completed sprint. Avoid `update`,
`changes`, `work`, `fix stuff`, `misc`.

### Test gate — do NOT commit if verification fails

Backend checks when backend code changed:

```bash
./vendor/bin/pint --test
php artisan test
```

Frontend checks when frontend code changed:

```bash
npm run lint
npm run build
```

Additional verification when relevant:

```bash
docker compose config -q
docker compose up -d
docker compose ps
```

For API / data changes, perform relevant endpoint / database verification.

### No partial sprint commits

Do not commit when:

- implementation is incomplete
- tests are failing
- build is failing
- verification has not been performed
- there is an unresolved architectural issue
- the user explicitly says not to commit
- the changes contain unrelated work from another sprint

If the current work is incomplete, leave it uncommitted and report why.

### Secret protection

**Never** commit `.env`, `backend/.env`, `frontend/.env`, API keys, OAuth
tokens, access tokens, passwords, private keys, or credentials. Verify staged
files before every commit.

Expected to remain gitignored: `.env`, `.env.*`, `backend/.env`, `frontend/.env`.

### Push policy

After a sprint passes all checks:

```bash
git push origin main
```

Use the currently configured branch if the repo is not on `main`. If push fails:
do **not** rewrite history, do **not** force push — report the exact failure,
keep the local commit, and do not create a duplicate commit.

### Commit safety

Never use `git push --force` or `git push --force-with-lease` unless the user
explicitly requests a history rewrite. Never run `git reset --hard`,
`git clean -fd`, or `git checkout -- .` to "clean up" unrelated work. Protect
existing user changes. Do not amend an existing commit unless explicitly
necessary. Prefer one clean commit per completed sprint/step.

### Post-commit verification

```bash
git status
git log -1 --oneline
git push origin <current-branch>
git status
```

Confirm the working tree is clean unless changes are intentionally left for
future work.

### Reporting

At the end of a completed sprint, report:

1. Tests passed
2. Build / lint status
3. Files committed
4. Commit hash
5. Commit message
6. Push result
7. Remaining uncommitted changes, if any

### Default behavior for future sprint/step work

```
Implement → Test → Verify →
  if sprint is explicitly complete: Commit → Push →
Report
```

The user remains the authority for scope. Do not commit unrelated experimental
changes just because tests pass. Do not commit half-finished features from the
next sprint.

## Domain model

`Token`, `MarketSnapshot`, `IngestionRun`, `HistoricalPeakEvidence`, `PumpEvent`,
`Evidence`, `PumpExplanation`, `QualificationEvent`, `TokenNarrativeReport` and
`TokenNarrativeSource` exist as tables. The rest are documented for naming
consistency only — do **not** create them yet.

| Concept          | Meaning | Status |
| ---------------- | ------- | ------ |
| `Token`          | A memecoin (chain + address identity). Unique on `(chain_id, token_address)`. Carries `observed_peak_market_cap` (+`_at`) = OUR OWN snapshot peak; SEPARATE `historical_peak_value` (+`_at`) = a VERIFIED/OBSERVED market cap that qualifies the main list; SEPARATE `historical_estimate_fdv_usd` (+`_at`) = a GeckoTerminal FDV-basis estimate (informational, never qualifies); `historical_peak_status` = the raw label. `first/last_observed_at`, `earliest_pair_created_at`. | **implemented (minimal)** |
| `MarketSnapshot` | One market observation for a token at `observed_at` (price, market cap, fdv, liquidity, volume, txns, primary pair). Many rows per token. No raw payloads. | **implemented (minimal)** |
| `IngestionRun`   | One execution of the discovery pipeline — `trigger` (`manual`/`scheduled`), `status` (`running`/`completed`/`failed`), `started/completed_at`, funnel counts, `error_message`, plus Step 19 trending-meta coverage (`trending_meta_count`, `trending_meta_pairs_seen`, `trending_meta_unique_candidates`, `pre_filtered_candidates`, `discovery_source_counts` json, `trending_meta_slugs_used` json). Observability only. | **implemented (minimal)** |
| `HistoricalPeakEvidence` | One row per token (upserted, re-evaluable): `status` (`CURRENT_OBSERVATION`/`HISTORICAL_VERIFIED`/`HISTORICAL_ESTIMATE`/`UNKNOWN`), `peak_value_usd`, `evidence_source` (dexscreener/coingecko/geckoterminal), `evidence_basis` (market_cap/fdv_total_supply/current_market_cap), `source_reference`, `confidence`, `checked_at`. No provider JSON. **Only `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` with `peak_value_usd` in `[$5M, $200M]` qualify the main list** (`qualifies($min, $max)`; `peakAboveCeiling($min, $max)` flags a peak that cleared the floor but exceeds the ceiling); `HISTORICAL_ESTIMATE` is `isInformationalEstimate()` only. | **implemented** |
| `Pair`           | A trading pair on a DEX for a token. | future |
| `Narrative`      | A theme/meta a token belongs to (e.g. "dog coins"). | future |
| `TokenRelation`  | A relationship between two tokens (co-movement, shared narrative, etc.). | future |
| `PumpEvent`      | One detected significant upward move in a token's OBSERVATION SERIES (our ~10-min snapshots — an "observed pump", not tick-level). `started/peak/ended_at`, `start/peak_market_cap`, `start/peak_price_usd`, `market_cap_change_pct`, `price_change_pct`, `volume_h24_change_ratio` + `txns_h24_change_ratio` (ROLLING 24h ratios, not interval), `duration_minutes`, `detection_score` (0–100 strength, not a prediction), `confidence` (low/medium/high), `status` (active/completed), `evidence_collected_at` (evidence-engine cooldown). `hasMany Evidence`. | **implemented (Step 16A)** |
| `Evidence`       | One timestamped FACT present around a `PumpEvent` (Step 16B) — `category` (`MARKET`/`TOKEN_METADATA`/`ORIGIN`/`NEWS`/`RELATED_TOKEN`; `LISTING`/`COMMUNITY` reserved), `source`, `source_url`, `title`, `observed_at`, `published_at`, `relevance_score` (0–100, investigative relevance NOT causation probability), `confidence` (low/medium/high), `summary` (neutral one-sentence fact), `raw_reference` (short id/domain/hash — no payload JSON), `dedupe_hash`. `belongsTo PumpEvent` + `belongsTo Token`. Stored SEPARATELY from interpretation — never asserts causality. See [docs/evidence-engine.md](docs/evidence-engine.md). | **implemented (Step 16B)** |
| `PumpExplanation` | One AI-generated, evidence-grounded interpretation per `PumpEvent` (Step 16C) — `status` (`pending`/`completed`/`failed`), `summary`, `primary_catalyst` (fixed enum incl. `UNKNOWN`), `confidence` (low/medium/high), `explanation_json` (full validated structure: summary / secondary_signals / evidence / caveats / unknowns — every claim cites evidence ids), `evidence_count`, `model_provider`, `model_name`, `error_message`, `generated_at`. Unique on `pump_event_id`; upserted on regeneration. The LLM INTERPRETS stored `Evidence` — never adds facts, never asserts causality. `belongsTo PumpEvent`. See [docs/pump-explanation.md](docs/pump-explanation.md). | **implemented (Step 16C)** |
| `QualificationEvent` | One "$5M crossing" per token per `type` (Step 20) — `type` (`CURRENT_OBSERVATION`/`HISTORICAL_VERIFIED`), `crossed_at` (earliest snapshot ≥ $5M, or earliest CoinGecko-verified ≥ $5M point — candled, never a tick), `threshold_usd`, `evidence_status`, `source` (`dexscreener`/`coingecko`), `market_cap_value`. **Unique on `(token_id, type)`** → idempotent scheduler re-runs; `crossed_at` never rewritten. `HISTORICAL_ESTIMATE`/`UNKNOWN` produce NO event. Only a verified/observed peak in `[$5M, $200M]` gets one. A token can hold both types; representative = `HISTORICAL_VERIFIED` > `CURRENT_OBSERVATION` (the other row preserved). Written ONLY by the pipeline (`QualificationEventRecorder`) — never a read API, never a snapshot scan on GET. `belongsTo Token` / `Token hasMany`. See [docs/qualification-events.md](docs/qualification-events.md). | **implemented (Step 20)** |
| `TokenNarrativeReport` | One token-level narrative synthesis per token (Step 21) — `origin_status` / `origin_summary` / `origin_explanation_json` (why created) + `popularity_status` / `popularity_summary` / `popularity_explanation_json` (why popular) + `overall_status` (`pending`/`completed`/`partial`/`failed`) / `overall_confidence` / `research_started_at` / `research_completed_at` / `generated_at` / `model_provider` / `model_name` / `research_providers_used` / `error_message` (concise, no traces). **Unique on `token_id`.** The AI INTERPRETS collected sources + our stored `Evidence` / market history — never browses, never invents sources/URLs/dates, never asserts creator intent, never causality-from-timing; every claim cites `token_narrative_sources.id`. Each section validated independently → one can be `completed` while the other `failed` (overall `partial`). Written ONLY by `memecoins:research-narratives`. `belongsTo Token` / `hasMany TokenNarrativeSource`. See [docs/token-narrative-intelligence.md](docs/token-narrative-intelligence.md). | **implemented (Step 21)** |
| `TokenNarrativeSource` | One concise source behind a narrative report (Step 21) — `section` (`origin`/`popularity`), `source_type` (`official`/`news`/`social`/`market`/`community`/`reference`), `source_name`, `source_url`, `title`, `published_at` (real or **null**, never fabricated), `accessed_at`, `claim` (one sentence), `relevance_score`, `confidence` (quality tier), `provider`. Metadata + claim only — **never a scraped page body**. `unique(token_narrative_report_id, dedupe_hash)` → idempotent re-research. Persisted BEFORE the AI call. `belongsTo TokenNarrativeReport` + `belongsTo Token`. | **implemented (Step 21)** |
| `MonthlyRanking` | Preserved Top-N leaders for a calendar month. | future |

## Processing pipeline (concept)

```
DISCOVER → NORMALIZE → FILTER → SCORE → SNAPSHOT → RELATE → EXPLAIN → DISPLAY
```

Do not prematurely define complex scoring algorithms. `SCORE` stays minimal
until a scoring rule is explicitly agreed.

## Sprint 1 — Memecoin Discovery (current focus)

**Goal: "Build the smallest useful live memecoin detector."**

In scope:

1. Discover newly launched memecoins.
2. Filter by pair age ≤ 30 days (`earliest_pair_created_at` = earliest DEX pool
   creation across the token's pairs; **not** token deploy time).
3. **Qualify for the main list when age ≤ 30 days AND a VERIFIED or OBSERVED
   market cap has EVER peaked inside the `$5M`–`$200M` band** (Step 19) —
   `CURRENT_OBSERVATION` (our own snapshot saw MC ≥ $5M — "observed peak", the
   highest MC our snapshots captured since `first_observed_at`, not a lifetime
   high) **or** `HISTORICAL_VERIFIED` (CoinGecko historical market cap), **AND**
   `GREATEST(observed_peak_market_cap, historical_peak_value) ≤ $200M`. The floor
   is a **peak** rule — a token that dumped *below* $5M after an in-band peak
   **stays qualified**. The ceiling is also on the peak — a token whose
   verified/observed MC **ever exceeded $200M is excluded even if its current MC
   is back in the band**; we never re-qualify on current MC alone.
   **`HISTORICAL_ESTIMATE` (GeckoTerminal FDV basis = peak price × total supply)
   does NOT qualify the main list** — it is an informational secondary signal,
   stored (`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`)
   and shown on the detail page as a clearly-labelled estimate, never a market
   cap. `UNKNOWN` = no safe evidence, never "did not reach $5M". **FDV never
   substitutes for market cap** (market cap = price × *circulating* supply; FDV =
   price × *total* supply). Config: `MEMECOIN_OBSERVED_PEAK_MAX_USD=200000000`.
   See *Historical Peak Qualification* in the Sprint 1 doc.
4. Cross-chain candidate discovery (chains are whatever the trending metas /
   activity feeds actually surface — never a hard-coded chain list).
5. Basic market metrics (price, market cap, FDV, liquidity, volume, price change,
   chain, DEX).
6. Persist each age-eligible observation (`tokens` + `market_snapshots`) so
   observed-peak history accrues over time. Cold start: for a token first seen
   today we cannot know whether it crossed $5M earlier — say "insufficient
   historical observation", never "never crossed $5M".
7. Display detected trending candidates in the React dashboard, showing the data
   source and retrieval timestamp.
8. (Step 20) Record **when** each token crossed the $5M floor
   (`qualification_events` — `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` only,
   never an estimate) and surface a **"Recently Crossed $5M"** dashboard section
   + detail-page qualification timeline. The $5M/$200M/age/volume/liquidity rules
   are unchanged.

Same pipeline runs two ways — HTTP `GET /api/memecoins/discover` (`trigger=manual`)
and the scheduled command `php artisan memecoins:discover` (`trigger=scheduled`,
every 10 min via Laravel's scheduler, `withoutOverlapping()`): DISCOVER (trending
meta → profiles → boosts → keyword fallback) → PRE-FILTER (on meta market data,
before enrichment) → DEDUPE → PRIORITIZE → ENRICH → NORMALIZE → AGE FILTER →
PERSIST TOKEN + SNAPSHOT → UPDATE OBSERVED PEAK → CURRENT OBSERVATION CHECK →
HISTORICAL LOOKUP → QUALIFICATION ($5M–$200M band) → RECORD QUALIFICATION EVENTS
(Step 20 — "$5M crossing") → PERSIST EVIDENCE.
Each run is recorded in `ingestion_runs`. No queue / Redis / Horizon —
synchronous execution. Details:
[docs/sprint-1-discovery.md](docs/sprint-1-discovery.md).

Explicitly **excluded** from Sprint 1:

- AI pump explanations
- Related-token graph
- Social sentiment
- Historical monthly rankings
- Notifications
- Portfolio management
- Trading execution
- Advanced scoring
- Authentication

## Current status

- Docker Compose stack: **`postgres` + `backend` (HTTP API) + `scheduler`
  (`schedule:work`) + `frontend`**. `docker compose up -d` starts everything;
  the scheduler runs automatically. `GET /api/health` on the backend.
- **DexScreener discovery service — trending-meta-first (Step 19)**
  (`GET /api/memecoins/discover`). Source priority: **1. Trending Meta** (the
  documented `GET /metas/trending/v1` → `GET /metas/meta/v1/{slug}` narrative
  APIs — `DexScreenerClient::trendingMetas()` / `metaBySlug()`; member pairs
  become `trending_meta` candidates, up to `DEXSCREENER_TRENDING_META_LIMIT`=18
  metas expanded), **2. Latest token profiles**, **3. Latest/top boosts**,
  **4. Keyword search fallback** (`SearchTermEngine` — retained, **OFF by
  default**, `MEMECOIN_KEYWORD_DISCOVERY_ENABLED=false`; supplemental long-tail
  only, never primary). Toggles: `config('dexscreener.discovery_sources.*')` /
  `DEXSCREENER_TRENDING_META_ENABLED` / `DEXSCREENER_PROFILES_ENABLED` /
  `DEXSCREENER_BOOSTS_ENABLED`. **The undocumented `io.dexscreener.com` WebSocket
  Trending table is NOT used** (Cloudflare-bot-walled, binary, versioned,
  unsupported — see docs/trending-discovery-reconnaissance.md). Profiles/boosts
  are secondary activity signals and must not outrank organic trending meta.
  **PRE-FILTER** (on the free meta market data, *before* `/token-pairs/v1`
  enrichment): `marketCap` present & > 0 & ≤ $200M; `volume.h24` > 0;
  `liquidity.usd` > 0; `pairCreatedAt` present; loose pair age ≤ 35 d
  (`MEMECOIN_PREFILTER_MAX_AGE_DAYS`, **performance only** — the strict age gate
  still uses `earliest_pair_created_at` = `min(pairCreatedAt)` across *all* pairs
  from full enrichment). **The $5M lower bound is NOT a pre-filter** (a token
  currently below $5M may have an earlier qualifying peak). Candidates are
  deduped, then enriched via `/token-pairs/v1`, normalized, age-filtered, and a
  `Token` + `MarketSnapshot` is **persisted** per age-eligible token, maintaining
  `observed_peak_market_cap`. The meta slug/name that surfaced a token is carried
  as `discovery_context` (`{ trending_meta_slug, trending_meta_name,
  trending_meta_count }`) in the discovery API response + diagnostics — no
  `tokens` column. Candidate source tags union and are never overwritten
  (`["trending_meta", "profile", "boost"]`). The **paid narrative-bar ad** is
  ignored (rows with no chain/address/pair). Three separate ceilings:
  `MEMECOIN_DISCOVERY_CANDIDATE_CAP` (500 unique candidates) → `MEMECOIN_MAX_ENRICH`
  (120 enriched) → `?limit=` (20 returned). Deterministic pre-enrichment
  prioritization: **1.** trending_meta source present, **2.** number of distinct
  trending metas, **3.** profile signal, **4.** boost signal, **5.** search hits,
  **6.** deterministic token key — **never market cap**. Representative pair =
  highest `liquidity.usd` (unchanged). Coverage diagnostics: `trending_meta_*`
  (`count` / `slugs_used` / `pairs_seen` / `unique_candidates` / `tokens_unique`
  / `prefilter_dropped` / `prefilter_reasons` / `ad_or_malformed_skipped`),
  `pre_filtered_candidates`, `deferred_candidates`, `keyword_discovery_enabled`,
  `search_terms_*`, `discovery_source_counts` (`{ trending_meta, profile, boost,
  search }`), `chains_discovered` (only chains actually observed),
  `candidate_cap_dropped`, `selected_for_enrichment`,
  `not_qualified_peak_above_ceiling`.
- **`GET /api/memecoins/discovery-status`** — read-only coverage report,
  PostgreSQL (`ingestion_runs`) only, never calls DexScreener. Latest run
  summary + latest completed run's discovery metrics + `trending_meta` coverage
  block (`meta_count` / `slugs_used` / `pairs_seen` / `unique_candidates`) +
  `sources` (`discovery_source_counts`) + `pre_filtered_candidates` +
  `chains_discovered` map. New `ingestion_runs` columns:
  `trending_meta_count`, `trending_meta_pairs_seen`,
  `trending_meta_unique_candidates`, `pre_filtered_candidates`,
  `discovery_source_counts` (json), `trending_meta_slugs_used` (json).
- **Historical qualification engine (Step 13C, Strategy D)** runs in the pipeline
  after the age filter: `CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP →
  QUALIFICATION → PERSIST EVIDENCE`. A token qualifies for the **main list** when
  age ≤ 30d **AND a VERIFIED / OBSERVED market cap has EVER peaked inside the
  `$5M`–`$200M` band** — via `CURRENT_OBSERVATION` (our own snapshot) or
  `HISTORICAL_VERIFIED` (CoinGecko non-zero market-cap point), with
  `GREATEST(observed_peak_market_cap, historical_peak_value) ≤ $200M`
  (`HistoricalPeakEvidence::qualifies($min, $max)` + `peakAboveCeiling()`;
  `MEMECOIN_OBSERVED_PEAK_MAX_USD`). A dump below $5M after an in-band peak stays
  qualified; a peak that ever cleared $200M is excluded and is **not** re-qualified
  on current MC (diagnostic `not_qualified_peak_above_ceiling`).
  `HistoricalQualificationService` itself is unchanged — the ceiling is a
  qualification-layer clause only. **`HISTORICAL_ESTIMATE`** (GeckoTerminal peak price
  × immutable total supply — an **FDV basis estimate, NOT a market cap**) is
  built and stored but **does NOT qualify the main list** — it mirrors to
  `tokens.historical_estimate_fdv_usd` (never `historical_peak_value`) and is an
  informational secondary signal on the detail page. `UNKNOWN` = no safe evidence
  (never "did not reach $5M"), stored and re-evaluated. External lookups only for
  tokens not already qualified on our own observed peak; 6 h re-lookup cooldown;
  per-run budget. `observed_peak_market_cap` is never overwritten. CoinGecko
  optional/resilient; `COINGECKO_API_KEY` never exposed to React. Adapters:
  `app/Services/Historical/{CoinGeckoClient,GeckoTerminalClient,HistoricalQualificationService}`.
  Config: `config/historical.php`. Do **not** remove GeckoTerminal.
- Scheduled ingestion: the `scheduler` container runs Laravel's `schedule:work`,
  firing `memecoins:discover --trigger=scheduled` every 10 min
  (`withoutOverlapping()`, file-cache lock, no Redis / Horizon / queue). Every
  run — HTTP or scheduled — is recorded in `ingestion_runs`; run summaries show
  in `docker compose logs scheduler`.
- **Read API `GET /api/memecoins`** — read-only, PostgreSQL only, never calls
  DexScreener / CoinGecko / GeckoTerminal. Returns **only** tokens qualified by
  `CURRENT_OBSERVATION` or `HISTORICAL_VERIFIED` with a verified/observed market
  cap peak **in `[$5M, $200M]`** (`GREATEST(observed_peak, historical_peak_value)`
  BETWEEN the two — a peak above $200M is excluded even when current MC is back in
  band). Each row carries `qualification_status` / `qualification_peak_value` /
  `qualification_peak_at` / `qualification_source` / `qualification_basis` (always
  `current_market_cap` or `market_cap`, never `fdv_total_supply`) — kept DISTINCT
  from `observed_peak_market_cap`. **`HISTORICAL_ESTIMATE` and `UNKNOWN` are
  excluded.** `meta.filters` carries `observed_peak_market_cap_min_usd` +
  `observed_peak_market_cap_max_usd`. Step 20 adds per-row
  `qualification_crossed_at` / `qualification_crossing_type` / `recently_crossed`
  (from the representative `QualificationEvent`; `null`/`false` when none
  recorded), `meta.sort` + `meta.recent_crossing_hours`, and `?sort=` — default
  **`peak_market_cap`** (`GREATEST(observed_peak, historical_peak_value)` DESC),
  or `recent_crossing` (representative `crossed_at` DESC, no-crossing tokens
  last). Default stays peak-ranked: the dashboard's "Recently Crossed $5M"
  section already serves recency. `?chain=` / `?limit=` (default 20, max 50).
  ≤ 3 queries + 1 for the events, no N+1.
- **Read API `GET /api/memecoins/recently-crossed`** (Step 20) — read-only,
  PostgreSQL only, never calls DexScreener / CoinGecko / GeckoTerminal, never
  writes, never creates an event. Returns currently-**qualified** tokens (age
  ≤ 30d, verified/observed peak in `[$5M, $200M]`) whose **representative**
  `QualificationEvent.crossed_at` is within the window (default 48h,
  `MEMECOIN_RECENT_CROSSING_HOURS`; `?hours=` 1…168 =
  `MEMECOIN_RECENT_CROSSING_MAX_HOURS`; optional `?chain=`), newest crossing
  first. **A token with current MC below $5M still appears** — the floor is a
  peak rule. Rows carry `status` `ACTIVE` (current MC ≥ $5M) / `COOLED` (< $5M) —
  never alarmist. `config('dexscreener.recent_crossing.*')`.
- **Detail API `GET /api/memecoins/{chainId}/{tokenAddress}`** — read-only,
  PostgreSQL only, never calls DexScreener / CoinGecko / GeckoTerminal. Identity
  is `(chain_id, token_address)`, never the symbol. **Nested response** (Step 15):
  `qualification` (MAIN-LIST qualification — `qualified` bool + `peak_value` = a
  verified/observed market cap, `null` for `HISTORICAL_ESTIMATE`/`UNKNOWN`/
  above-ceiling; `ineligible_reason: "peak_above_ceiling"` when a verified/observed
  peak cleared $5M but exceeds $200M, else `null`),
  **`historical_estimate`** (Step 17-fix — an explicitly-named FDV-basis block:
  `estimated_fdv_usd` / `estimate_source` / `estimate_basis` /
  `estimate_confidence` + disclaimer; `null` unless a `HISTORICAL_ESTIMATE`
  evidence row exists; there is **no `historical_market_cap` key**), `observed`
  (our own peak — kept DISTINCT from both), `latest` (most recent snapshot),
  `pair`, `snapshots` (≤ 50, newest first), `pump_intelligence` (Step 16C — ≤ 10 recent
  pump events, each with its persisted AI `explanation` + `presented` prose +
  `cited_evidence`; `status: "pending"` when not yet generated; **never triggers
  AI generation**), **`qualification_timeline`** (Step 20 — representative
  `crossed_at` / `crossing_type` / `crossing_source` / `crossing_market_cap_value`
  / `recently_crossed` / `currently_below_threshold` / `threshold_usd` / `events[]`;
  all-null / empty when no crossing recorded), `provenance`. **Dashboard
  qualification is NOT an existence gate** — any stored `Token` resolves. Miss →
  `404 {"error": "Memecoin not found."}`. ≤ 8 queries, no N+1.
- **React dashboard** reads `GET /api/memecoins` + `GET /api/memecoins/recently-crossed`
  only. **Two sections** (Step 20): **🔥 Recently Crossed $5M** (compact card
  list — Token / Chain / Crossed / Current MC / Peak MC / `ACTIVE`|`COOLED`)
  above the existing **30-Day Qualified Memecoins** table (chain filter, Sort
  control Peak market cap / Recent crossing, Refresh + 60s auto-refresh,
  loading/empty/error states, provenance notes). Rows link to the detail page;
  each row has a copy-contract-address button and a compact
  `CURRENT`/`VERIFIED`/`ESTIMATE` badge (unchanged). Never talks to DexScreener.
- **React token detail page** (`/memecoin/:chainId/:tokenAddress`, React Router)
  reads `GET /api/memecoins/{chainId}/{tokenAddress}` only (for data). Sections:
  header (middle-truncated CA + copy + back link), **live market chart** (Step 17
  — embedded DexScreener `<iframe>` built from `chain_id` +
  `latest.primary_pair_address`, format-checked, never `token_address`; null pair
  → "Live chart unavailable"), market overview (stat cards incl. **Observed Peak
  MC vs Qualification Peak**, ESTIMATE → "Estimated — FDV basis"), **"Why is this
  token on the list?"** (status-coloured evidence card — UNKNOWN never "did not
  reach $5M"), **"Qualification timeline"** (Step 20 — Crossed $5M / Crossing
  type / MC at crossing / Current MC / Peak MC; below-$5M → "remains qualified
  because it previously crossed", never "currently above"; placeholder when none
  recorded), **pump events** (Step 16A–C — timeline `started→peak` / MC % /
  price % / detection score+confidence / status, each expands to the persisted
  AI "why did this coin pump?" explanation with expandable cited evidence;
  `pending`/`failed`/`UNKNOWN` show neutral notes, never a guessed reason),
  market activity, observation history (sparkline + table), token identity,
  **Token narrative intelligence** (Step 21 — two-column block: "Why it became
  popular" = headline / summary / chronological timeline / dominant factors /
  confidence / sources; "Why it was created" = origin type / headline / summary /
  supporting facts / confidence / sources. Stacks on mobile. Each factual line
  cites expandable `token_narrative_sources`; `pending`/`partial`/`failed` show a
  neutral note, never a stack trace; never inferred creator intent, never
  causality from timing), data provenance.
  Reads persisted data/explanations only — never calls AI or DexScreener from
  JS; the chart iframe is the only third-party content.
  `frontend/src/{api,types,lib,components,pages}`.
- **Pump event detection (Step 16A)** — `php artisan memecoins:detect-pumps`,
  scheduled `5,15,25,35,45,55 * * * *` (same cadence as discovery, offset so it
  runs *after* ingestion; reuses the `scheduler` container; `withoutOverlapping`).
  `PumpDetectionService` reads only stored snapshots — **never** DexScreener /
  CoinGecko / GeckoTerminal. Deterministic: over the last ~24 snapshots per
  recently-observed token it compares latest vs ~60-min-earlier, requires a
  ≥ 50% MC **or** ≥ 40% price move **plus** ≥ 2 total confirming signals (MC /
  price / rolling-24h `volume_h24_change_ratio` / rolling-24h
  `txns_h24_change_ratio`), scores 0–100 (strength, not prediction), assigns
  low/medium/high confidence, and creates or **merges** a `pump_events` row
  (one continuous pump = one event; separate pumps = separate events).
  Config: `config/pump.php` (heuristic MVP thresholds). **"When", not "why" —
  no catalysts/AI (that is 16B/16C).**
- **Evidence engine (Step 16B)** — `php artisan memecoins:collect-evidence`
  `[--force]`, scheduled `8,18,28,38,48,58 * * * *` (a few minutes *after* pump
  detection; reuses the `scheduler` container; `withoutOverlapping`). Collects
  timestamped **facts** around each recent `PumpEvent` inside a bounded window
  (`started_at − 60m` … `peak_at + 30m`) → `evidences` rows. Four collectors in
  `app/Services/Evidence/`: `MarketEvidenceCollector` (PG only — event metrics +
  snapshots), `TokenMetadataEvidenceCollector` (PG only — stored token links +
  pool age), `RelatedTokenEvidenceCollector` (PG only — other tracked tokens
  that rose ≥ 40% in the lead window before the event; **not** the future
  `TokenRelation` graph; never `high` confidence), `NewsEvidenceCollector` (the
  **only** external call — GDELT 2.1 DOC API, free/no-key; bounded per-run
  request budget; any failure logged + skipped, never fails the command; no
  fabricated evidence). Deterministic `relevance_score` 0–100 (**investigative
  relevance, not causation probability**); `EvidenceRecorder` upserts on
  `(pump_event_id, dedupe_hash)` (idempotent); per-event
  `evidence_collected_at` cooldown (2h). **Evidence is stored separately from
  interpretation and never asserts causality** — "published 12 min before the
  observed pump peak", never "caused the pump". No AI (that is Step 16C). No
  frontend changes. Config: `config/evidence.php`. Docs:
  `docs/evidence-engine.md`.
- **AI pump explanation (Step 16C)** — `php artisan memecoins:explain-pumps`
  `[--force]`, scheduled `9,19,29,39,49,59 * * * *` (a minute *after* evidence
  collection; reuses the `scheduler` container; `withoutOverlapping`). The LLM is
  an **INTERPRETER of stored `Evidence`, never a data source** — it sees one
  `PumpEvent` + its ranked, capped evidence (`≤ PUMP_EXPLANATION_MAX_EVIDENCE`),
  never the wider DB, never browses. Vendor-agnostic: `PumpExplanationProvider`
  interface, provider chosen by `AI_PROVIDER` (`anthropic` default via forced
  tool-call; `null` = never call out, always fail). `PumpExplanationValidator`
  hard-rejects malformed output, out-of-enum values, hallucinated/uncited
  evidence ids, and **causal language** ("caused/triggered the pump") → recorded
  `failed`, never a fabricated fallback. Output: `summary`, `primary_catalyst`
  (fixed enum incl. `UNKNOWN`), `secondary_signals[]`, `evidence[]` (**every
  claim cites evidence ids**), `confidence`, `caveats[]`, `unknowns[]` →
  `pump_explanations` (one per event, upserted; regeneratable — `generated_at` +
  6h cooldown). Evidence text is sent as **untrusted data** in a delimited block,
  never in the system prompt. `PumpExplanationPresenter` derives the UI prose
  ("Most supported explanation", never "Confirmed reason"; UNKNOWN → "no verified
  catalyst established", never "we don't know"). **The read API never calls the
  provider** — generation is CLI/scheduler only. `ANTHROPIC_API_KEY` server-side
  only. Config: `config/ai.php`. Docs: `docs/pump-explanation.md`.
- **Qualification events / "Recently Crossed $5M" (Step 20)** — the discovery
  pipeline gains a `RECORD QUALIFICATION EVENTS` step (after QUALIFICATION,
  before PERSIST EVIDENCE): `QualificationEventRecorder` upserts a
  `qualification_events` row per token per crossing `type`
  (`CURRENT_OBSERVATION` = earliest snapshot ≥ $5M; `HISTORICAL_VERIFIED` =
  earliest CoinGecko-verified ≥ $5M point, from the new
  `historical_peak_evidences.first_verified_crossing_at`). `HISTORICAL_ESTIMATE`
  / `UNKNOWN` produce **no** event; only a verified/observed peak in
  `[$5M, $200M]` qualifies. `(token_id, type)` unique → idempotent, `crossed_at`
  never rewritten. Representative crossing = `HISTORICAL_VERIFIED` >
  `CURRENT_OBSERVATION`. Read APIs never create an event or scan snapshots.
  Surfaces on `GET /api/memecoins` (`qualification_crossed_at` /
  `qualification_crossing_type` / `recently_crossed`, `?sort=recent_crossing`),
  the new `GET /api/memecoins/recently-crossed`, and the detail
  `qualification_timeline`. Backfill is lazy — a pre-Step-20 token gets its event
  the next time it's rediscovered while still age-eligible. Config:
  `config/dexscreener.php` → `recent_crossing.*`. Docs:
  `docs/qualification-events.md`.
- **Token Narrative Intelligence (Step 21)** — `php artisan memecoins:research-narratives`
  `[--force] [--token=chain:address]`, scheduled **hourly** (`0 * * * *`,
  `withoutOverlapping(30)`; reuses the `scheduler` container) — SLOW + externally
  dependent, never on the 10-min cadence, never blocks discovery/pumps. Two
  token-level questions, SEPARATE from the pump explanation:
  **"Why was this coin created?"** (origin) + **"Why did this coin become
  popular?"** (popularity). `NarrativeResearchService` finds sources via a
  configurable `NarrativeResearchProvider` list (`NARRATIVE_RESEARCH_PROVIDERS`,
  default `internal,gdelt` — `internal` = our own `Evidence` + token metadata +
  `PumpEvent`s + `$5M` crossings, always available; `gdelt` = token-level news,
  degrades to none on failure), ranks them by quality tier (official / reference /
  reputable-news / internal-market = HIGH; anonymous / repost = LOW — many weak
  sources never outrank one strong primary), persists `token_narrative_sources`
  **before** the AI call, then asks the `NarrativeExplanationProvider` (SEPARATE
  binding, `NARRATIVE_AI_PROVIDER`; `null` = fail, never fabricate) via forced
  `record_token_narrative` tool call. `NarrativeExplanationValidator` rejects
  malformed output, out-of-enum values, hallucinated/uncited source ids, uncited
  facts, **creator-intent claims** (origin), and **causal language** (popularity)
  → that section `failed`. Timeline is sorted chronologically. Source text is
  untrusted data (delimited block, never the system prompt) — same injection
  defense as Step 16C. Sections validate independently → `partial` when one
  succeeds. 24h per-token cooldown (`TOKEN_NARRATIVE_RESEARCH_COOLDOWN_HOURS`),
  `TOKEN_NARRATIVE_MAX_TOKENS_PER_RUN` (10). Read API `GET /api/memecoins/{chain}/{addr}`
  exposes `data.token_narrative` (origin + popularity + sources) — **never
  triggers research, never leaks provider error detail**; `pending` with no
  report. `ANTHROPIC_API_KEY` server-side only. Config: `config/narrative.php`.
  Docs: `docs/token-narrative-intelligence.md`.
- Tables: `tokens`, `market_snapshots`, `ingestion_runs`,
  `historical_peak_evidences`, `pump_events`, `evidences`, `pump_explanations`,
  `qualification_events`, `token_narrative_reports`, `token_narrative_sources`.
  No queue / trend score / related-token graph / auth.
