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
│   │   ├── components/  MemecoinTable, ChainFilter, CopyAddress, MarketCapSparkline
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

`Token`, `MarketSnapshot`, `IngestionRun`, `HistoricalPeakEvidence`, `PumpEvent`
and `Evidence` exist as tables. The rest are documented for naming consistency
only — do **not** create them yet.

| Concept          | Meaning | Status |
| ---------------- | ------- | ------ |
| `Token`          | A memecoin (chain + address identity). Unique on `(chain_id, token_address)`. Carries `observed_peak_market_cap` (+`_at`) = OUR OWN snapshot peak; SEPARATE `historical_peak_value` (+`_at`) = a VERIFIED/OBSERVED market cap that qualifies the main list; SEPARATE `historical_estimate_fdv_usd` (+`_at`) = a GeckoTerminal FDV-basis estimate (informational, never qualifies); `historical_peak_status` = the raw label. `first/last_observed_at`, `earliest_pair_created_at`. | **implemented (minimal)** |
| `MarketSnapshot` | One market observation for a token at `observed_at` (price, market cap, fdv, liquidity, volume, txns, primary pair). Many rows per token. No raw payloads. | **implemented (minimal)** |
| `IngestionRun`   | One execution of the discovery pipeline — `trigger` (`manual`/`scheduled`), `status` (`running`/`completed`/`failed`), `started/completed_at`, funnel counts, `error_message`. Observability only. | **implemented (minimal)** |
| `HistoricalPeakEvidence` | One row per token (upserted, re-evaluable): `status` (`CURRENT_OBSERVATION`/`HISTORICAL_VERIFIED`/`HISTORICAL_ESTIMATE`/`UNKNOWN`), `peak_value_usd`, `evidence_source` (dexscreener/coingecko/geckoterminal), `evidence_basis` (market_cap/fdv_total_supply/current_market_cap), `source_reference`, `confidence`, `checked_at`. No provider JSON. **Only `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` qualify the main list** (`qualifies()`); `HISTORICAL_ESTIMATE` is `isInformationalEstimate()` only. | **implemented** |
| `Pair`           | A trading pair on a DEX for a token. | future |
| `Narrative`      | A theme/meta a token belongs to (e.g. "dog coins"). | future |
| `TokenRelation`  | A relationship between two tokens (co-movement, shared narrative, etc.). | future |
| `PumpEvent`      | One detected significant upward move in a token's OBSERVATION SERIES (our ~10-min snapshots — an "observed pump", not tick-level). `started/peak/ended_at`, `start/peak_market_cap`, `start/peak_price_usd`, `market_cap_change_pct`, `price_change_pct`, `volume_h24_change_ratio` + `txns_h24_change_ratio` (ROLLING 24h ratios, not interval), `duration_minutes`, `detection_score` (0–100 strength, not a prediction), `confidence` (low/medium/high), `status` (active/completed), `evidence_collected_at` (evidence-engine cooldown). `hasMany Evidence`. | **implemented (Step 16A)** |
| `Evidence`       | One timestamped FACT present around a `PumpEvent` (Step 16B) — `category` (`MARKET`/`TOKEN_METADATA`/`ORIGIN`/`NEWS`/`RELATED_TOKEN`; `LISTING`/`COMMUNITY` reserved), `source`, `source_url`, `title`, `observed_at`, `published_at`, `relevance_score` (0–100, investigative relevance NOT causation probability), `confidence` (low/medium/high), `summary` (neutral one-sentence fact), `raw_reference` (short id/domain/hash — no payload JSON), `dedupe_hash`. `belongsTo PumpEvent` + `belongsTo Token`. Stored SEPARATELY from interpretation — never asserts causality. See [docs/evidence-engine.md](docs/evidence-engine.md). | **implemented (Step 16B)** |
| `PumpExplanation` | One AI-generated, evidence-grounded interpretation per `PumpEvent` (Step 16C) — `status` (`pending`/`completed`/`failed`), `summary`, `primary_catalyst` (fixed enum incl. `UNKNOWN`), `confidence` (low/medium/high), `explanation_json` (full validated structure: summary / secondary_signals / evidence / caveats / unknowns — every claim cites evidence ids), `evidence_count`, `model_provider`, `model_name`, `error_message`, `generated_at`. Unique on `pump_event_id`; upserted on regeneration. The LLM INTERPRETS stored `Evidence` — never adds facts, never asserts causality. `belongsTo PumpEvent`. See [docs/pump-explanation.md](docs/pump-explanation.md). | **implemented (Step 16C)** |
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
   market cap has EVER reached $5M** — `CURRENT_OBSERVATION` (our own snapshot saw
   MC ≥ $5M — "observed peak", the highest MC our snapshots captured since
   `first_observed_at`, not a lifetime high) **or** `HISTORICAL_VERIFIED`
   (CoinGecko historical market cap). **`HISTORICAL_ESTIMATE` (GeckoTerminal
   FDV basis = peak price × total supply) does NOT qualify the main list** — it
   is an informational secondary signal, stored
   (`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`) and shown
   on the detail page as a clearly-labelled estimate, never a market cap.
   `UNKNOWN` = no safe evidence, never "did not reach $5M". **FDV never
   substitutes for market cap** (market cap = price × *circulating* supply; FDV =
   price × *total* supply). See *Historical Peak Qualification* in the Sprint 1
   doc.
4. Cross-chain candidate discovery.
5. Basic market metrics (price, market cap, FDV, liquidity, volume, price change,
   chain, DEX).
6. Persist each age-eligible observation (`tokens` + `market_snapshots`) so
   observed-peak history accrues over time. Cold start: for a token first seen
   today we cannot know whether it crossed $5M earlier — say "insufficient
   historical observation", never "never crossed $5M".
7. Display detected trending candidates in the React dashboard, showing the data
   source and retrieval timestamp.

Same pipeline runs two ways — HTTP `GET /api/memecoins/discover` (`trigger=manual`)
and the scheduled command `php artisan memecoins:discover` (`trigger=scheduled`,
every 10 min via Laravel's scheduler, `withoutOverlapping()`): DISCOVER → ENRICH →
NORMALIZE → AGE FILTER → PERSIST TOKEN + SNAPSHOT → UPDATE OBSERVED PEAK →
CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP → QUALIFICATION → PERSIST EVIDENCE.
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
- DexScreener discovery service implemented (`GET /api/memecoins/discover`):
  discovers candidates from profiles / boosts / a budgeted **search-term engine**
  (`SearchTermEngine`: core meme terms → trending-meta slugs → meta names →
  ecosystem terms, deduped, capped at `MEMECOIN_SEARCH_TERM_BUDGET`=25), enriches
  via `/token-pairs/v1`, normalizes, age-filters, and **persists** a `Token` +
  `MarketSnapshot` per age-eligible token, maintaining `observed_peak_market_cap`.
  Three separate ceilings: `MEMECOIN_DISCOVERY_CANDIDATE_CAP` (500 unique
  candidates) → `MEMECOIN_MAX_ENRICH` (120 enriched) → `?limit=` (20 returned).
  Deterministic pre-enrichment prioritization (source count → boost → profile
  freshness → search hits → token key; **never market cap**). Coverage
  diagnostics: `search_terms_*`, `discovery_source_counts`, `chains_discovered`
  (counted from candidates actually seen), `candidate_cap_dropped`,
  `selected_for_enrichment`.
- **`GET /api/memecoins/discovery-status`** — read-only coverage report,
  PostgreSQL (`ingestion_runs`) only, never calls DexScreener. Latest run
  summary + latest completed run's discovery metrics + `chains_discovered` map.
- **Historical qualification engine (Step 13C, Strategy D)** runs in the pipeline
  after the age filter: `CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP →
  QUALIFICATION → PERSIST EVIDENCE`. A token qualifies for the **main list** when
  age ≤ 30d **AND a VERIFIED / OBSERVED market cap has EVER reached $5M** — via
  `CURRENT_OBSERVATION` (our own snapshot) or `HISTORICAL_VERIFIED` (CoinGecko
  non-zero market-cap point). **`HISTORICAL_ESTIMATE`** (GeckoTerminal peak price
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
  `CURRENT_OBSERVATION` or `HISTORICAL_VERIFIED` (a verified/observed market cap
  ≥ $5M). Each row carries `qualification_status` / `qualification_peak_value` /
  `qualification_peak_at` / `qualification_source` / `qualification_basis` (always
  `current_market_cap` or `market_cap`, never `fdv_total_supply`) — kept DISTINCT
  from `observed_peak_market_cap`. **`HISTORICAL_ESTIMATE` and `UNKNOWN` are
  excluded.** Sorted by `GREATEST(observed_peak, historical_peak_value)` DESC.
  `?chain=` / `?limit=` (default 20, max 50). ≤ 3 queries, no N+1.
- **Detail API `GET /api/memecoins/{chainId}/{tokenAddress}`** — read-only,
  PostgreSQL only, never calls DexScreener / CoinGecko / GeckoTerminal. Identity
  is `(chain_id, token_address)`, never the symbol. **Nested response** (Step 15):
  `qualification` (MAIN-LIST qualification — `qualified` bool + `peak_value` = a
  verified/observed market cap, `null` for `HISTORICAL_ESTIMATE`/`UNKNOWN`),
  **`historical_estimate`** (Step 17-fix — an explicitly-named FDV-basis block:
  `estimated_fdv_usd` / `estimate_source` / `estimate_basis` /
  `estimate_confidence` + disclaimer; `null` unless a `HISTORICAL_ESTIMATE`
  evidence row exists; there is **no `historical_market_cap` key**), `observed`
  (our own peak — kept DISTINCT from both), `latest` (most recent snapshot),
  `pair`, `snapshots` (≤ 50, newest first), `pump_intelligence` (Step 16C — ≤ 10 recent
  pump events, each with its persisted AI `explanation` + `presented` prose +
  `cited_evidence`; `status: "pending"` when not yet generated; **never triggers
  AI generation**), `provenance`. **Dashboard qualification is NOT an existence
  gate** — any stored `Token` resolves. Miss →
  `404 {"error": "Memecoin not found."}`. ≤ 6 queries, no N+1.
- **React dashboard** ("30-Day Leaders") reads `GET /api/memecoins` only —
  table, chain filter, Refresh + 60s auto-refresh, loading/empty/error states,
  provenance notes. Rows link to the detail page; each row has a
  copy-contract-address button and a compact `CURRENT`/`VERIFIED`/`ESTIMATE`
  qualification badge. Never talks to DexScreener.
- **React token detail page** (`/memecoin/:chainId/:tokenAddress`, React Router)
  reads `GET /api/memecoins/{chainId}/{tokenAddress}` only (for data). Sections:
  header (middle-truncated CA + copy + back link), **live market chart** (Step 17
  — embedded DexScreener `<iframe>` built from `chain_id` +
  `latest.primary_pair_address`, format-checked, never `token_address`; null pair
  → "Live chart unavailable"), market overview (stat cards incl. **Observed Peak
  MC vs Qualification Peak**, ESTIMATE → "Estimated — FDV basis"), **"Why is this
  token on the list?"** (status-coloured evidence card — UNKNOWN never "did not
  reach $5M"), **pump events** (Step 16A–C — timeline `started→peak` / MC % /
  price % / detection score+confidence / status, each expands to the persisted
  AI "why did this coin pump?" explanation with expandable cited evidence;
  `pending`/`failed`/`UNKNOWN` show neutral notes, never a guessed reason),
  market activity, observation history (sparkline + table), token identity,
  **"Why was this coin created?"** (placeholder, or stored ORIGIN/TOKEN_METADATA
  evidence as plain facts when present — never inferred intent), data provenance.
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
- Tables: `tokens`, `market_snapshots`, `ingestion_runs`,
  `historical_peak_evidences`, `pump_events`, `evidences`, `pump_explanations`.
  No queue / trend score / related-token graph / auth.
