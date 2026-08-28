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
4. External APIs must be accessed from Laravel, never directly from the browser.
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
├── frontend/           React + TypeScript + Vite ("30-Day Leaders" dashboard)
│   ├── src/
│   │   ├── api/         memecoins.ts — fetches GET /api/memecoins (Laravel only)
│   │   ├── types/       memecoin.ts
│   │   ├── lib/         format.ts ($74.6M / 8d / timestamps)
│   │   ├── components/  MemecoinTable.tsx, ChainFilter.tsx
│   │   └── App.tsx
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

## Domain model

`Token` and `MarketSnapshot` exist as **minimal** tables (Sprint 1). The rest are
documented for naming consistency only — do **not** create them yet.

| Concept          | Meaning | Status |
| ---------------- | ------- | ------ |
| `Token`          | A memecoin (chain + address identity). Unique on `(chain_id, token_address)`. Carries `observed_peak_market_cap` + `observed_peak_market_cap_at` + `first/last_observed_at` + `earliest_pair_created_at`. | **implemented (minimal)** |
| `MarketSnapshot` | One market observation for a token at `observed_at` (price, market cap, fdv, liquidity, volume, txns, primary pair). Many rows per token. No raw payloads. | **implemented (minimal)** |
| `IngestionRun`   | One execution of the discovery pipeline — `trigger` (`manual`/`scheduled`), `status` (`running`/`completed`/`failed`), `started/completed_at`, funnel counts, `error_message`. Observability only. | **implemented (minimal)** |
| `Pair`           | A trading pair on a DEX for a token. | future |
| `Narrative`      | A theme/meta a token belongs to (e.g. "dog coins"). | future |
| `TokenRelation`  | A relationship between two tokens (co-movement, shared narrative, etc.). | future |
| `PumpEvent`      | A detected significant upward move for a token/pair. | future |
| `Evidence`       | A stored artifact backing a claim (URL, payload, timestamp, type). | future |
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
3. **Qualify by observed peak market cap ≥ $5M.** A token qualifies if age ≤ 30
   days **and it has ever been observed by our snapshots at market cap ≥ $5M** —
   current market cap may be lower. "Observed peak" = highest MC our own
   snapshots have captured since `first_observed_at`, **not** a guaranteed
   lifetime / all-time high. FDV never substitutes for market cap.
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
NORMALIZE → AGE FILTER → PERSIST TOKEN + SNAPSHOT → UPDATE OBSERVED PEAK → QUALIFY
BY OBSERVED PEAK. Each run is recorded in `ingestion_runs`. No queue / Redis /
Horizon — synchronous execution. Details:
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
  discovers candidates from profiles / boosts / curated search, enriches via
  `/token-pairs/v1`, normalizes, age-filters, and **persists** a `Token` +
  `MarketSnapshot` per age-eligible token, maintaining `observed_peak_market_cap`.
  Qualification = age ≤ 30 days AND observed peak MC ≥ $5M.
- Scheduled ingestion: the `scheduler` container runs Laravel's `schedule:work`,
  firing `memecoins:discover --trigger=scheduled` every 10 min
  (`withoutOverlapping()`, file-cache lock, no Redis / Horizon / queue). Every
  run — HTTP or scheduled — is recorded in `ingestion_runs`; run summaries show
  in `docker compose logs scheduler`.
- **Read API `GET /api/memecoins`** — read-only, PostgreSQL only, never calls
  DexScreener. Returns qualified tokens (age ≤ 30d AND observed peak MC ≥ $5M)
  with current fields from the latest `MarketSnapshot`, sorted by observed peak
  DESC. `?chain=` / `?limit=` (default 20, max 50). 2 queries, no N+1.
- **React dashboard** ("30-Day Leaders") reads `GET /api/memecoins` only —
  table, chain filter, Refresh + 60s auto-refresh, loading/empty/error states,
  provenance notes. `frontend/src/{api,types,lib,components}`. Never talks to
  DexScreener.
- Tables: `tokens`, `market_snapshots`, `ingestion_runs` (minimal). No queue /
  ranking / AI / relations / auth.
