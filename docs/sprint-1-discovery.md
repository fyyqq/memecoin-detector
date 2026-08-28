# Sprint 1 — Memecoin Discovery Service

Backend implementation of
`DISCOVER → ENRICH → NORMALIZE → AGE FILTER → PERSIST → UPDATE OBSERVED PEAK → QUALIFY`.

Background / API capability analysis: [dexscreener-reconnaissance.md](dexscreener-reconnaissance.md).

---

## Business rule

> **Eligibility requires age ≤ 30 days AND observed peak market cap ≥ $5M.**
> "Observed peak" means the highest market cap captured by **our own snapshots**
> since we first saw the token — **not** a guaranteed lifetime / all-time /
> historical high. Current market cap may be below $5M and the token still
> qualifies. FDV never substitutes for market cap.

| Age | Observed peak MC | Current MC | Result |
|---|---|---|---|
| 12 d | $11M (stored) | $2M | **QUALIFIED** |
| 12 d | $4M | $3M | not qualified (`observed_peak_below_threshold`) |
| 12 d | — (first seen today) | $7M | **QUALIFIED** — the $7M is stored as the first observed peak |
| 12 d | — (first seen today) | $3M | not qualified (`insufficient_historical_observation` — we do **not** claim it never crossed $5M) |
| 45 d | $20M | — | not qualified (`older_than_max_age`) |

### Cold-start limitation

For a token first observed today, the detector cannot know whether it reached
$5M before today. Historical accuracy therefore **improves the longer the
detector runs and keeps taking snapshots** (see *Historical Observation Model*
below). The scheduler (every 10 min) is what actually builds this history.

The API never asserts "never crossed $5M". When the observed peak is below the
threshold *and was itself set from a current reading*, the reason is
`observed_peak_below_threshold`; when we have no usable market cap at all it is
`insufficient_historical_observation`.

---

## Historical Observation Model

`tokens.observed_peak_market_cap` is **the highest market cap captured by our own
`market_snapshots` since `first_observed_at`** — the moment our detector started
observing that token.

It is **not** a guaranteed lifetime / all-time / historical peak. We never
back-fill history we did not capture, and we never infer that a token "never
crossed $5M" — only that *our observations* have not seen it above the threshold
yet (`insufficient_historical_observation`).

How history accrues:

- The scheduler runs `memecoins:discover` **every 10 minutes**. Each run
  discovers candidates, and for every **age-eligible** token appends one
  `market_snapshots` row and raises `observed_peak_market_cap` if the current
  market cap is known and strictly higher.
- A token that pumped to $11M and fell back to $2M **stays qualified** because a
  snapshot recorded the $11M.
- The longer the scheduler runs, the closer the observed peak approaches the true
  historical peak — but the two are only guaranteed to match for tokens we have
  watched since before their peak.
- Accuracy for pre-existing tokens is limited by when we first saw them. A
  dedicated historical provider (GeckoTerminal) is out of scope for Sprint 1.

---

## Docker services

`docker compose up -d` starts the **complete** development environment — the
scheduler runs automatically, no extra command needed.

| Service | Process | Host port | Role |
|---|---|---|---|
| `postgres` | `postgres:16-alpine` | 5433 | database |
| `backend` | `php artisan serve` | 8010 | HTTP API only |
| `scheduler` | `php artisan schedule:work` | — (none) | scheduled ingestion only |
| `frontend` | `npm run dev` (Vite) | 5180 | React dashboard |

`backend` and `scheduler` are the **same image** (`docker/backend/Dockerfile`)
with the **same environment** (declared once via the `x-backend-env` YAML anchor —
DB credentials are never duplicated). They differ only in the process they run.
`scheduler` waits for `backend` to be healthy before starting (so migrations and
`composer install` happen once), and both use `migrate --isolated` so a fresh-DB
start never migrates concurrently. `scheduler` has `restart: unless-stopped` and
**no host-exposed port**.

## Scheduled ingestion

```
scheduler container: php artisan schedule:work
        │  (every minute, internally)
        ▼
   */10 * * * *  ──▶ php artisan memecoins:discover --trigger=scheduled
                          │
                          ▼
              DexScreenerDiscoveryService::discover(trigger: scheduled)
                          │  (same pipeline as the HTTP endpoint)
                          ▼
       DexScreener ▶ normalize ▶ age filter ▶ persist token + snapshot ▶ update peak
```

- **Command:** `php artisan memecoins:discover` — thin wrapper over
  `DexScreenerDiscoveryService`; no DexScreener logic of its own. Options:
  `--trigger=scheduled|manual` (default `scheduled`), `--chain=<id>`. Prints a
  concise count summary (and `Log::info`s it); exits non-zero on failure.
- **Cadence:** `routes/console.php` →
  `Schedule::command('memecoins:discover --trigger=scheduled')->cron('*/N * * * *')->withoutOverlapping(15)->sendOutputTo(...)`.
  `N` = `MEMECOIN_DISCOVERY_INTERVAL_MINUTES` (default 10, clamped 1–60).
- **Overlap protection — why it matters:** the enrichment fan-out takes 15–30 s.
  Without `withoutOverlapping()`, a slow run still going when the next 10-minute
  tick fires would launch a second copy — doubling DexScreener load and racing
  two writers on the same `tokens` / `market_snapshots` rows. The lock uses the
  default **file** cache store (`CACHE_STORE=file`) — no Redis.
- **Execution model:** synchronous inside the scheduled command. **No queue
  worker, no Redis, no Horizon.** Infra stays Laravel + PostgreSQL + DexScreener.
- Enrichment concurrency stays bounded at 10 (`DEXSCREENER_ENRICH_CONCURRENCY`).
- **Logs:** `docker compose logs -f scheduler` shows each run —
  `… Running ['artisan' memecoins:discover --trigger=scheduled] Memecoin discovery completed.` followed by the count summary
  (`Ingestion run: #… / Raw candidates / … / Qualified`) and `… DONE`. The
  scheduled command's stdout is redirected to the container's PID 1 stdout
  (`/proc/1/fd/1`); off-container it falls back to `storage/logs/schedule.log`.

### Manual / debug scheduler commands

The scheduler container makes these unnecessary for normal use, but they help
when debugging:

```bash
docker compose logs -f scheduler                              # watch scheduled runs
docker compose exec scheduler php artisan schedule:list       # what's registered / next due
docker compose exec scheduler php artisan schedule:clear-cache  # release a stuck withoutOverlapping mutex
docker compose exec backend php artisan memecoins:discover    # run one ingestion by hand (trigger=scheduled)
docker compose exec backend php artisan memecoins:discover --trigger=manual
```

---

## Ingestion runs (`ingestion_runs`)

Every pipeline execution — HTTP or scheduled — records one row for observability.
It does **not** drive behaviour.

| Column | Note |
|---|---|
| `trigger` | `manual` (HTTP `GET /api/memecoins/discover`) or `scheduled` (the command) |
| `status` | `running` → `completed`, or `running` → `failed` |
| `started_at` / `completed_at` | |
| `raw_candidates`, `unique_candidates`, `enriched_candidates`, `age_eligible`, `snapshots_written`, `new_tokens`, `peak_updated`, `qualified` | filled on completion from the run diagnostics |
| `error_message` | concise message (≤ 480 chars, no stack trace) on failure |

- The HTTP response echoes `meta.ingestion_run_id` and `meta.retrieved_at`.
- On an **unexpected** pipeline exception the run is marked `failed`, the message
  stored, and the exception re-thrown: the HTTP endpoint returns a safe
  `503 { "error": "…" }` (no trace); the command prints the error and exits `1`.
- Provider outages are still handled gracefully *inside* the client (empty
  results, `status = completed`) — only genuine bugs/infra faults mark a run
  `failed`.

---

## Read API and Dashboard

```
Ingestion (writes)                         Read (dashboard)
──────────────────                         ────────────────
scheduler / manual                         browser
  → memecoins:discover / POST-ish GET         → GET /api/memecoins   (Laravel)
  → DexScreenerDiscoveryService                 → Token + latest MarketSnapshot
  → DexScreener (enrich)                         → PostgreSQL  (read only)
  → normalize → age filter                     → React "30-Day Leaders" table
  → PostgreSQL (tokens, market_snapshots)
```

**`GET /api/memecoins`** — read-only. It **never** calls DexScreener, never
writes, never runs discovery. Query params: `?chain=<id>` (any chain id; the
frontend dropdown is just a convenience list), `?limit=` (default 20, server max
50). Qualification is the **same rule** as ingestion — `earliest_pair_created_at`
within `max_age_days` of now **AND** `observed_peak_market_cap >= $5M` — sorted
by `observed_peak_market_cap` DESC ("30-Day Leaders"). No momentum scoring.

Response:

```jsonc
{
  "data": [
    {
      "id": 55, "chain_id": "solana", "token_address": "…",
      "name": "Dogecoin", "symbol": "DOGE",
      "current_market_cap": 74613000,          // from the LATEST market_snapshots row
      "observed_peak_market_cap": 74613000,     // from the tokens row
      "observed_peak_market_cap_at": "2026-08-28T07:13:03+00:00",
      "age_days": 8.07,                         // now − earliest_pair_created_at
      "liquidity_usd": 74479668.03,             // latest snapshot
      "volume_h24": 69398.3,                    // latest snapshot
      "primary_dex_id": "raydium",              // latest snapshot
      "primary_pair_address": "…",              // latest snapshot
      "data_source": "dexscreener",             // constant (only source today)
      "last_observed_at": "2026-08-28T07:54:23+00:00"
    }
  ],
  "meta": {
    "count": 2,
    "retrieved_at": "2026-08-28T07:54:41+00:00",
    "filters": { "max_age_days": 30, "observed_peak_market_cap_min_usd": 5000000 }
  }
}
```

**Query strategy (no N+1):** `Token::latestSnapshot()` is a
`hasOne(...)->latestOfMany('observed_at')` relation, eager-loaded with `->with()`.
One query for the filtered/sorted tokens, one window-function subquery join for
their latest snapshots — **2 queries total, independent of row count**.

**Why the frontend never calls DexScreener:** a discovery run takes 15–30 s and
hits rate-limited endpoints. The browser must stay fast and must not multiply
provider load per viewer. The dashboard therefore only reads already-persisted
observations through Laravel; ingestion is the scheduler's job.

**Dashboard** (`frontend/`, React + TS + Vite):
`src/api/memecoins.ts` (fetch, typed, abortable) · `src/types/memecoin.ts` ·
`src/lib/format.ts` (`$74.6M`, `8d`, timestamps) ·
`src/components/{MemecoinTable,ChainFilter}.tsx` · `src/App.tsx`.
States: loading / ready / empty / error ("Unable to load memecoin data.", no
stack traces). Chain dropdown re-queries the API. Manual **Refresh** button plus
a gentle 60 s auto-refresh (one `GET /api/memecoins` per tick — no aggressive
polling). Footer shows `Data source: DexScreener`, last-observed time, and the
note *"Observed peak reflects the highest market cap captured by this detector,
not guaranteed lifetime history"* — never labelled "ATH".

---

---

## Ingestion endpoint (`GET /api/memecoins/discover`)

The heavy pipeline, also runnable over HTTP (`trigger = manual`). The dashboard
does **not** use this — see *Read API and Dashboard* above.

```
GET /api/memecoins/discover
```

| Query param | Type | Default | Notes |
|---|---|---|---|
| `limit` | int ≥ 1 | `20` | Applies to the **qualified** result list. Clamped to a server max of `50`. Does **not** limit how many observations are persisted. |
| `chain` | string `[A-Za-z0-9_-]+` | — | Optional `chain_id` filter, applied **before** enrichment/persistence. |

Invalid params → `422`. Provider outage → `200` with `data: []` + diagnostics
(never `500`; nothing persisted).

### Response shape

```jsonc
{
  "data": [
    {
      "token_key": "solana:ci11...qdx4",
      "chain_id": "solana",
      "token_address": "Ci11...QDx4",
      "name": "Doge", "symbol": "DOGE",

      "current_market_cap": 2100000,          // nullable — from the latest snapshot
      "observed_peak_market_cap": 11800000,   // nullable — highest our snapshots have seen
      "observed_peak_market_cap_at": "2026-08-28T09:00:00+00:00",
      "observed_since": "2026-08-20T12:00:00+00:00", // token.first_observed_at

      "fdv": 12500000,                        // nullable
      "liquidity_usd": 1200000,               // nullable
      "volume_h24": 4300000,                  // nullable
      "price_usd": 0.0021,                    // nullable
      "price_change_h24": -3.2,               // nullable
      "txns_h24": 512, "buys_h24": 300, "sells_h24": 212, // nullable

      "primary_pair_address": "…", "primary_dex_id": "raydium",
      "pair_count": 3,

      "earliest_pair_created_at": "2026-08-20T06:07:17+00:00", // nullable, DEX pool creation (NOT token deploy)
      "age_days": 12.4,                       // nullable

      "size_basis": "market_cap",             // "market_cap" | "fdv" | "unknown"
      "sources": ["search", "boost"],
      "data_source": "dexscreener",
      "retrieved_at": "2026-08-28T12:00:00+00:00"
    }
  ],
  "meta": {
    "count": 1,
    "limit": 20,
    "chain": null,
    "filters": { "max_age_days": 30, "observed_peak_market_cap_min_usd": 5000000 },
    "coverage_note": "Activity- and keyword-driven sample; not an exhaustive token census.",
    "observed_peak_note": "observed_peak_market_cap is the highest market cap captured by our own snapshots since observed_since — not a guaranteed lifetime high.",
    "diagnostics": { … see below … },
    "not_qualified_sample": [
      { "token_key", "chain_id", "symbol", "reason", "current_market_cap", "fdv", "observed_peak_market_cap", "age_days" }
    ]
  }
}
```

Missing values are JSON `null`, **never coerced to `0`**.

---

## Persistence (minimal — Sprint 1)

Two tables. No raw provider payloads are stored.

### `tokens` — one row per memecoin

| Column | Note |
|---|---|
| `chain_id`, `token_address` | **Identity. Unique constraint `(chain_id, token_address)`.** Never the symbol. |
| `symbol`, `name` | Refreshed from the latest observation (never overwritten with null). |
| `earliest_pair_created_at` | Earliest non-null `pairCreatedAt` across the token's pairs. DEX pool creation, **not** token deploy. |
| `first_observed_at` | Set once on creation, **never moved forward**. → `observed_since` in the API. |
| `last_observed_at` | Updated every observation. |
| `observed_peak_market_cap` | Highest market cap our snapshots have recorded. Raised only when a **known** current MC is strictly higher; a null MC never lowers/clears it. |
| `observed_peak_market_cap_at` | Timestamp of the observation that set the current peak. |

### `market_snapshots` — many rows per token

`token_id`, `observed_at`, `price_usd`, `market_cap`, `fdv`, `liquidity_usd`,
`volume_h24`, `price_change_h24`, `txns_h24`, `buys_h24`, `sells_h24`,
`primary_pair_address`, `primary_dex_id`, `earliest_pair_created_at`.

Every discovery call appends one snapshot per age-eligible token. Repeated calls
accumulate rows by design (future scheduler will pace this).

Tests use `RefreshDatabase` against a **dedicated Postgres database**
`memecoin_test` (project rule: no SQLite), forced in `phpunit.xml`. Create it once:

```bash
docker compose exec postgres createdb -U memecoin memecoin_test
```

---

## Architecture

| Class | Responsibility |
|---|---|
| `Http\Controllers\Api\MemecoinDiscoveryController` | Validate query, clamp `limit`, shape the JSON response. |
| `Services\DexScreener\DexScreenerClient` | Transport only: base URL from config, timeouts, bounded retries, 429/5xx handling, short-lived response cache, bounded-concurrency enrichment batch. Every failure degrades to `[]`. |
| `Services\DexScreener\DexScreenerDiscoveryService` | The pipeline. Age is the only pre-persistence gate; qualification is by stored observed peak. Builds diagnostics. |
| `Services\DexScreener\DexScreenerNormalizer` | Pure: one raw pair list → one `TokenCandidateData`. Time injected. |
| `Services\DexScreener\TokenObservationService` | `DB::transaction`: find-or-create `Token` on `(chain_id, token_address)`, refresh name/symbol/earliest_pair_created_at, append a `MarketSnapshot`, raise `observed_peak_market_cap`. |
| `Services\DexScreener\RecordedObservation` | `{ token, snapshot, tokenWasCreated, peakUpdated, previousObservedPeak }`. |
| `Services\DexScreener\DiscoveryResult` | `{ candidates, diagnostics, notQualifiedSample }`. |
| `DTOs\DexScreener\TokenCandidateData` | Immutable normalized current observation. |
| `DTOs\DexScreener\QualifiedCandidate` | `TokenCandidateData` + persisted peak figures → `toArray()` = the API item. |

Config: [`config/dexscreener.php`](../backend/config/dexscreener.php). The DexScreener
base URL is **always** `config('dexscreener.base_url')` ← `DEXSCREENER_BASE_URL`
— never hardcoded in business logic.

---

## Discovery sources

Configured, easy to edit, **not exhaustive**:

| Source | Endpoint | Contributes | `sources` tag |
|---|---|---|---|
| latest token profiles | `/token-profiles/latest/v1` | `chainId` + `tokenAddress` | `profile` |
| latest token boosts | `/token-boosts/latest/v1` | `chainId` + `tokenAddress` | `boost` |
| top token boosts | `/token-boosts/top/v1` | `chainId` + `tokenAddress` | `boost` |
| trending metas | `/metas/trending/v1` | narrative slugs/names → extra **search terms** only | — |
| curated search terms | `/latest/dex/search?q=` | `baseToken.address` + `chainId` per returned pair | `search` |

Search terms = `MEMECOIN_SEARCH_TERMS` (default
`pepe,doge,cat,dog,wif,inu,meme,shib,bonk,elon`) plus up to
`DEXSCREENER_TRENDING_META_TERMS` (default 5) trending meta names/slugs.
Multi-source hits union their tags (deduped, order preserved).

---

## Pipeline detail

1. **DISCOVER + dedupe** — every raw hit → `token_key = lower(chainId):lower(tokenAddress)`.
   Dedupe on `token_key` only (never pair address, never symbol). Optional
   `?chain=` filter applied here.
2. **Prioritise + cap** — order by (sources desc, not-`["profile"]`-only, discovery
   order); enrich up to `MEMECOIN_MAX_ENRICH` (120) — the cap is independent of
   `limit` because every enriched, age-eligible token yields a stored snapshot.
3. **ENRICH** — `GET /token-pairs/v1/{chainId}/{tokenAddress}` via
   `tokenPairsBatch()`: 60 s response cache, misses fetched in bounded concurrent
   batches of `DEXSCREENER_ENRICH_CONCURRENCY` (default 10). Per-token failure →
   token dropped for this run, never aborts the request.
4. **NORMALIZE** — representative pair = highest `liquidity.usd` (deterministic
   fallback: smallest `pairAddress` when no pair reports liquidity). No hard
   liquidity filter. `earliest_pair_created_at = min(pairCreatedAt)` over non-null
   pairs; `age_days = (now − earliest_ms) / 86_400_000`.
5. **AGE FILTER** (the only pre-persistence gate):
   - `earliest_pair_created_at` null → `age_unknown`, **not persisted**.
   - `age_days > 30` → `older_than_max_age`, **not persisted**.
6. **PERSIST** — `TokenObservationService::record()`: find-or-create Token,
   append MarketSnapshot, raise observed peak if current MC is known and higher.
7. **QUALIFY** — `observed_peak_market_cap ≥ $5M` (from the Token row, post-update).
   `null` current MC does not disqualify a token whose stored peak already clears
   the bar.
8. **Sort + limit** — observed peak desc, then youngest, then slice to `limit`.

---

## Diagnostics (`meta.diagnostics`)

Counts only — no provider payloads.

```
raw_discovery_candidates          all (chainId,address) hits, with duplicates
unique_candidates                 after token_key dedupe
candidates_after_chain_filter     after optional ?chain=
enrichment_attempted              capped candidate count
enriched_ok / enrichment_failed
age_unknown                       excluded: every pairCreatedAt null (not persisted)
older_than_max_age                excluded: age > 30d (not persisted)
age_eligible                      passed the age gate → persisted
market_cap_unknown                age-eligible tokens whose current snapshot MC is null
snapshots_written                 MarketSnapshot rows appended this run
persist_failed                    DB write failed for a candidate (logged, skipped)
new_tokens / existing_tokens      Token rows created vs already present
peak_updated                      observed_peak_market_cap raised this run
qualified                         age ≤ 30d AND observed peak ≥ $5M
qualified_from_current_observation  qualified because THIS run's reading pushed the peak ≥ $5M
not_qualified                     age-eligible but observed peak < $5M (or unknown)
observed_peak_below_threshold     subset of not_qualified where a peak value exists but is < $5M
returned                          == meta.count
```

`meta.not_qualified_sample` holds up to 50 age-eligible non-qualifiers with their
`current_market_cap` / `fdv` / `observed_peak_market_cap`, so an FDV-only or
below-threshold outcome stays auditable.

---

## Caching

Laravel default store (`CACHE_STORE=file` locally — **no Redis**).
`DEXSCREENER_DISCOVERY_CACHE_TTL` / `DEXSCREENER_ENRICHMENT_CACHE_TTL` (both 60 s
default). Only successful responses cached. Matches DexScreener's own
`Cache-Control: public, max-age=60`.

---

## Configuration (`.env`)

```
DEXSCREENER_BASE_URL=https://api.dexscreener.com
DEXSCREENER_TIMEOUT=8
DEXSCREENER_CONNECT_TIMEOUT=4
DEXSCREENER_RETRIES=2
DEXSCREENER_ENRICH_CONCURRENCY=10
MEMECOIN_SEARCH_TERMS=pepe,doge,cat,dog,wif,inu,meme,shib,bonk,elon
MEMECOIN_OBSERVED_PEAK_MIN_USD=5000000
MEMECOIN_MAX_AGE_DAYS=30
MEMECOIN_DISCOVERY_INTERVAL_MINUTES=10   # scheduled ingestion cadence (1..60)
# optional: MEMECOIN_DEFAULT_LIMIT, MEMECOIN_MAX_LIMIT, MEMECOIN_MAX_ENRICH,
#           DEXSCREENER_TRENDING_META_TERMS, *_CACHE_TTL
```

---

## Limitations

- **Sample, not a census.** Coverage = curated search terms + DexScreener's paid
  activity feeds. `meta.coverage_note` states this.
- **Observed peak ≠ lifetime high.** See *Historical Observation Model* —
  accuracy grows as the scheduler keeps taking snapshots; it only matches the
  true peak for tokens watched since before that peak.
- **`pairCreatedAt` = pool creation, not token launch.** Stored as
  `earliest_pair_created_at`, never `token_created_at`.
- **`market_cap` depends on DexScreener knowing circulating supply.** When null
  we only have FDV — a different metric — and it never feeds `market_cap`.
- **Enrichment capped** at 120 tokens/run.
- **Snapshot rows accumulate** every run (scheduled every 10 min + any manual
  call). Intentional — that is how history is built. No retention/rollup yet.
- **Stuck `withoutOverlapping` mutex.** If the scheduler container is killed
  mid-run the cache lock can linger (it self-expires after 15 min);
  `php artisan schedule:clear-cache` releases it immediately.
- **No production deploy.** The dev stack is `docker compose up -d`; a hardened
  image / process supervisor / cron is still a later infra step.

---

## Tests

All against the `memecoin_test` Postgres DB (`RefreshDatabase`), HTTP fully
mocked (`Tests\Concerns\FakesDexScreener`) — no live calls.

- **`Unit/DexScreener/DexScreenerNormalizerTest`** — pure normalizer.
- **`Feature/MemecoinDiscoveryTest`** — HTTP endpoint: current MC ≥ $5M qualifies
  / new token at $7M qualifies immediately / current < $5M but stored peak ≥ $5M
  stays qualified / current & peak < $5M not qualified / later lower obs keeps
  peak / later higher obs raises peak (+ `observed_peak_market_cap_at`) / null MC
  keeps peak / FDV never substitutes / age > 30d excluded & not persisted / null
  `pairCreatedAt` excluded & not persisted / duplicate discovery → one Token, N
  snapshots / same symbol different chains stay separate / snapshots preserve
  each observation / `first_observed_at` never moves forward / earliest
  `pairCreatedAt` chosen / null liquidity no fatal error / provider 5xx → `200`
  empty & nothing persisted / `limit` clamp / `chain` filter / invalid → `422` /
  diagnostics counts / `meta.ingestion_run_id` present / manual run recorded
  `completed` / unexpected failure → `503` + run marked `failed`.
- **`Feature/DiscoverMemecoinsCommandTest`** — command invokes the service as
  `scheduled` & exits 0 / service throw → exit 1 / `--trigger` override /
  real pipeline records a `completed` scheduled run / unexpected error → run
  `failed` + exit 1 / repeated runs = separate `ingestion_runs`, no dup tokens /
  peak stays monotonic across two runs.
- **`Feature/DiscoverySchedulerTest`** — `memecoins:discover` is scheduled
  `*/10 * * * *` / carries `--trigger=scheduled` / uses `withoutOverlapping()` /
  appears in `schedule:list`.
- **`Feature/DockerComposeSchedulerTest`** — static checks on `docker-compose.yml`
  (no Docker daemon): exactly `{postgres, backend, scheduler, frontend}` /
  scheduler runs `schedule:work` / reuses the backend image / shares the
  `*backend-env` anchor with `DB_HOST: postgres` / bind-mounts the app source /
  `depends_on` postgres healthy / **no `ports:`** / `restart: unless-stopped`.
- **`Feature/MemecoinListTest`** — `GET /api/memecoins`: returns only qualified
  tokens / current MC < $5M with observed peak ≥ $5M still qualifies / observed
  peak < $5M excluded / age > 30d excluded / **latest** snapshot supplies the
  current fields / `chain` filter / sort by observed peak DESC / `limit` works &
  is clamped / invalid params → `422` / DexScreener never called
  (`Http::assertNothingSent`) / empty DB → `{data: [], meta: {count: 0}}` /
  timestamps are ISO 8601.
