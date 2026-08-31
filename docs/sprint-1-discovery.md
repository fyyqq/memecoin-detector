# Sprint 1 — Memecoin Discovery Service

Backend implementation of
`DISCOVER → ENRICH → NORMALIZE → AGE FILTER → PERSIST → UPDATE OBSERVED PEAK → QUALIFY`.

Background / API capability analysis: [dexscreener-reconnaissance.md](dexscreener-reconnaissance.md).

---

## Business rule

> **Eligibility requires age ≤ 30 days AND a VERIFIED or OBSERVED market-cap
> peak in `[$5M, $200M]`** (Step 19 — a bounded universe). The *qualifying peak*
> is the highest market cap we trust: `CURRENT_OBSERVATION` (our own DexScreener
> snapshot saw MC ≥ $5M) or `HISTORICAL_VERIFIED` (CoinGecko verified historical
> MC ≥ $5M).
>
> - The **floor** is a *peak* rule: a token whose **current** MC has dumped
>   below $5M **stays qualified** if an earlier observation / historical evidence
>   already cleared it. It is never re-disqualified on current MC alone.
> - The **ceiling** applies to the greatest verified/observed peak: a token that
>   ever printed a peak **> $200M** is **excluded** even if its current MC is far
>   lower.
> - Also required (where the field is available on the current/representative
>   pair): `volume_h24 > 0` and `liquidity_usd > 0`.
>
> **FDV never substitutes for market cap.** An FDV-based historical estimate
> (GeckoTerminal peak price × total supply, `HISTORICAL_ESTIMATE`) is
> **informational only** — it does **not** qualify a token for the main list.

**Market Cap** = price × **circulating** supply (the real tradable size).
**FDV** = price × **total** supply (assumes every token is already circulating).
For a memecoin with a treasury / vesting / un-minted allocation, FDV can be many
times the real market cap — which is why an FDV estimate cannot put a token in
the $5M–$200M market-cap universe.

| Age | Qualifying peak (observed/verified) | Current MC | Result |
|---|---|---|---|
| 12 d | $11M | $2M | **QUALIFIED** — floor is a peak rule |
| 12 d | $80M | $2M | **QUALIFIED** |
| 12 d | $4M | $3M | not qualified (`observed_peak_below_threshold`) |
| 12 d | $320M | $80M | **not qualified** (`qualifying_peak_above_ceiling`) — peak exceeded $200M |
| 12 d | — (first seen today) | $7M | **QUALIFIED** — the $7M is stored as the first observed peak |
| 12 d | — (first seen today) | $250M | not qualified — persisted, but peak > $200M ceiling |
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
- Accuracy for pre-existing tokens is limited by when we first saw them.
  Step 13C adds a **historical qualification engine** (below) that recovers many
  of these; anything it cannot verify stays `UNKNOWN` (never "did not cross the
  threshold").

---

## Historical Peak Qualification (Step 13C — Strategy D)

**Business rule (revised):** a token qualifies for the **main list** when
**age ≤ 30 days AND a VERIFIED or OBSERVED market cap has EVER reached ≥ $5M** —
i.e. `CURRENT_OBSERVATION` (our own snapshot) or `HISTORICAL_VERIFIED`
(CoinGecko). It qualifies *immediately* the first time our own observation sees
`market_cap ≥ $5M`, and stays qualified after the price falls, as long as it is
still within the 30-day window.

**`HISTORICAL_ESTIMATE` (FDV basis) and `UNKNOWN` do NOT qualify the main list.**
The estimate engine still runs and its output is fully preserved
(`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`) as an
informational secondary signal shown on the detail page — it is never a market
cap and never returned by `GET /api/memecoins`.

The engine runs in the discovery pipeline, after the age filter and observation
persistence:

```
… → AGE FILTER → PERSIST TOKEN + SNAPSHOT
  → CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP → QUALIFICATION ($5M–$200M)
  → RECORD QUALIFICATION EVENTS (Step 20) → PERSIST EVIDENCE → RETURN
```

### Evidence statuses

| Status | Meaning | Source | Basis | Qualifies the main list? |
|---|---|---|---|---|
| `CURRENT_OBSERVATION` | our own snapshot observed `market_cap ≥ $5M` | dexscreener | `current_market_cap` | ✅ |
| `HISTORICAL_VERIFIED` | CoinGecko has a **non-zero** historical market-cap point `≥ $5M` | coingecko | `market_cap` | ✅ |
| `HISTORICAL_ESTIMATE` | GeckoTerminal peak hourly price × **immutable** total supply `≥ $5M` — **FDV basis, NOT a market cap** | geckoterminal | `fdv_total_supply` | ❌ **informational only** — stored (`tokens.historical_estimate_fdv_usd`) + shown on the detail page as a labelled secondary signal, never on the main list |
| `UNKNOWN` | no safe evidence — **never** "did not reach $5M" | — | — | ❌ (kept for re-evaluation) |

Precedence (which status is written when several could apply):
`HISTORICAL_VERIFIED` > `CURRENT_OBSERVATION` > `HISTORICAL_ESTIMATE` > `UNKNOWN`.
Only the first two qualify the token for the main list.

### Token columns (kept strictly separate)

| Column | Holds |
|---|---|
| `observed_peak_market_cap` (+`_at`) | highest MC **our own snapshots** captured — never overwritten by any external / estimated value |
| `historical_peak_value` (+`_at`) | a **verified / observed market cap** ≥ $5M — set only for `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED`. Qualifies the main list. |
| `historical_estimate_fdv_usd` (+`_at`) | a GeckoTerminal **FDV-basis estimate** — set only for `HISTORICAL_ESTIMATE`. Informational; never qualifies. |
| `historical_peak_status` | the raw status label (all four values) |

### Cold-start behaviour

> Token launched 2 days ago, spiked to ~$8M, crashed to $1M, discovered only
> after the crash. Our snapshots only ever see $1M.

- If still ≥ $5M at first enrichment → `CURRENT_OBSERVATION`, qualified that
  cycle.
- If already crashed → CoinGecko (usually 404 this young). If CoinGecko returns a
  verified historical market-cap point ≥ $5M → `HISTORICAL_VERIFIED`, qualified.
- Otherwise GeckoTerminal hourly OHLCV since pool creation recovers
  `peak_price × total_supply ≈ $8M` → `HISTORICAL_ESTIMATE` **if** the
  supply-safety gate passes. This is an **FDV estimate** — the token is **NOT**
  added to the main list; the estimate is stored and surfaced on the detail page
  as a secondary signal. If the gate fails → `UNKNOWN`.

### CoinGecko verification

`GET /coins/{platform}/contract/{address}` → if a coin id exists,
`GET /coins/{id}/market_chart/range` over `[earliest_pair_created_at … now]`.
The maximum **non-zero** `market_caps` point ≥ $5M → `HISTORICAL_VERIFIED`.

- **404 "coin not found"** → CoinGecko unavailable for this token → GeckoTerminal
  fallback.
- **All-zero `market_caps`** (listed but circulating supply unverified) → treated
  as **NOT verified** → GeckoTerminal fallback.
- Prices / FDV are never converted to market cap.
- Optional `COINGECKO_API_KEY` (Demo key, `x-cg-demo-api-key`). Bounded per run,
  responses cached 6 h, 429 retried once and then abandoned. Never exposed to
  React.

### GeckoTerminal estimate

1. Best pool = highest `reserve_in_usd` (deterministic; **not** a claim to
   represent every DEX market).
2. Hourly OHLCV from pool creation to now (one page covers ≤ 30 days).
   `peak_price = max(high)`.
3. Total supply from `/tokens/{addr}` (`normalized_total_supply`).
4. **Supply-safety gate** — a `HISTORICAL_ESTIMATE` is allowed only when:
   - total supply is present and > 0, **and**
   - the mint is **defensibly immutable** — `mint_authority` explicitly `null`
     (Solana) → *medium* confidence. No signal (most EVM) → rejected as
     `UNKNOWN` unless `HISTORICAL_ESTIMATE_ALLOW_UNVERIFIED_SUPPLY=true`, then
     *low* confidence. A present mint authority (mutable supply) → **rejected**.
5. `estimate = peak_price × total_supply`. `estimate ≥ $5M` →
   `HISTORICAL_ESTIMATE` (`evidence_basis = fdv_total_supply`), else `UNKNOWN`.
   **This estimate is an FDV, not a market cap** — it is mirrored to
   `tokens.historical_estimate_fdv_usd`, never `historical_peak_value`, and does
   not qualify the token for `GET /api/memecoins`.

### `UNKNOWN` handling

`UNKNOWN` tokens are **not** in the qualified list and are **never** described as
having failed to cross $5M — only that we have no verified history before
`first_observed_at`. Their evidence row is stored and re-evaluated.

### Lookup cooldown / budget (performance)

- External lookups happen **only** for age-eligible tokens that are **not
  already** qualified on our own observed peak.
- `HISTORICAL_LOOKUP_COOLDOWN_HOURS` (default **6**): a token is not re-looked-up
  until its `checked_at` is older than the cooldown. `HISTORICAL_VERIFIED` is
  terminal (never re-checked); `CURRENT_OBSERVATION` is free every run;
  `HISTORICAL_ESTIMATE` / `UNKNOWN` are re-checked after the cooldown (so an
  un-indexed token can later become `VERIFIED`).
- `HISTORICAL_MAX_LOOKUPS_PER_RUN` (default **15**) plus per-provider
  `*_MAX_CALLS_PER_RUN` caps bound provider load per discovery run. Lookups run
  sequentially. Provider responses are cached 6 h.

### Persistence

- **`historical_peak_evidences`** — one row per token (`token_id` unique),
  upserted, re-evaluable. `status`, `peak_value_usd`, `peak_observed_at`,
  `evidence_source` (`dexscreener`/`coingecko`/`geckoterminal`), `evidence_basis`
  (`market_cap`/`fdv_total_supply`/`current_market_cap`), `source_reference`
  (short pointer, no JSON), `historical_window_start/end`, `confidence`,
  `checked_at`, `notes` (one line). No provider payloads.
- **`tokens.historical_peak_value` / `_at` / `_status`** — denormalized headline
  for fast read-API filtering. **`observed_peak_market_cap` is never written by
  this engine** and remains OUR OWN snapshot peak.

Config: [`config/historical.php`](../backend/config/historical.php).

---

## Qualification Events — "Recently Crossed $5M" (Step 20)

Records **when** a token first crossed the `$5M` floor so the dashboard can
answer *"which tracked memecoins have most recently crossed $5M?"* — not just
show a static ranking. **Nothing about the qualification universe changes.**

The pipeline step `RECORD QUALIFICATION EVENTS` (after `QUALIFICATION`, before
`PERSIST EVIDENCE`) upserts one `qualification_events` row per token per **type**:

| `type` | `crossed_at` | `source` |
|---|---|---|
| `CURRENT_OBSERVATION` | earliest `market_snapshots.observed_at` with `market_cap ≥ $5M` | `dexscreener` |
| `HISTORICAL_VERIFIED` | earliest CoinGecko verified `≥ $5M` point (`historical_peak_evidences.first_verified_crossing_at`, else `peak_observed_at`) — a *candled* "historically verified crossing", never an exact tick | `coingecko` |

`HISTORICAL_ESTIMATE` and `UNKNOWN` produce **no** crossing event. Only tokens
whose verified/observed peak sits in `[$5M, $200M]` get an event (same
"qualified" definition as the main list). `(token_id, type)` is unique →
repeated scheduler runs are idempotent and `crossed_at` is never rewritten. A
token can hold both types; the **representative** crossing is the strongest
(`HISTORICAL_VERIFIED > CURRENT_OBSERVATION`) and the other row is preserved.

`App\Services\Historical\QualificationEventRecorder` — one query pre-loads the
batch's existing events, one indexed snapshot query per new `CURRENT_OBSERVATION`
crossing. Read APIs never create an event and never scan snapshots.

Full detail (data model, precedence, ACTIVE/COOLED, the 48h window, limitations):
[`docs/qualification-events.md`](qualification-events.md).

Config: `config/dexscreener.php` → `recent_crossing.hours` (48) /
`recent_crossing.max_hours` (168) ← `MEMECOIN_RECENT_CROSSING_HOURS` /
`MEMECOIN_RECENT_CROSSING_MAX_HOURS`.

---

## Pump Event Detection (Step 16A)

**Deterministic detection of significant sudden upward moves** in tracked
memecoins. Answers *"WHEN did this coin experience a pump?"* — **not** *"why"*
(catalysts / evidence are Step 16B/16C).

### It operates on OUR observation series

`MarketSnapshot` rows are periodic detector observations (~10 min apart), **not
tick-level trades**. Everything here is an **"observed pump"**, never an "exact
market pump". Timestamps are snapshot `observed_at` values — never claimed to be
exact tick boundaries.

**No external calls.** `PumpDetectionService` reads only stored snapshots —
never DexScreener / CoinGecko / GeckoTerminal. Pipeline:
`DexScreener → snapshots → PumpDetectionService`.

### Pipeline

```
eligible tokens (last_observed_at recent, ≥ minimum_snapshots)
  → per token: PumpDetector over the recent snapshot window → ?PumpDetection
  → PumpEventRecorder — create a new pump_events row, or MERGE into the token's
    most recent overlapping event
  → sweep: active events whose peak is stale → completed
```

### Detection window

Primary comparison: the latest observation vs the observation **closest to
~`primary_minutes` (60) earlier**, accepting anything within
`tolerance_minutes` (±20) since snapshots are ~10 min apart. Inside that window
the detector picks the highest-market-cap observation as the **peak** and the
lowest at-or-before it as the **start** (the trough the move rose from). A
shorter `acceleration_minutes` (~25) comparison adds a small score bonus for
rapid recent moves. A token needs ≥ `acceleration_minutes` of history to be
analysed at all.

### Signals (`start → peak`)

| Signal | Computation | Caveat |
|---|---|---|
| `market_cap_change_pct` | `(peak.market_cap − start.market_cap) / start.market_cap × 100` | null if either MC null / ≤ 0 |
| `price_change_pct` | same, on `price_usd` | null if either null / ≤ 0 |
| **`volume_h24_change_ratio`** | `peak.volume_h24 / start.volume_h24` | **ROLLING 24h ratio — NOT 1-hour volume growth.** Directional confirmation only. |
| **`txns_h24_change_ratio`** | `peak.txns_h24 / start.txns_h24` (`txns_h24 = buys + sells`) | **ROLLING 24h ratio — NOT interval transaction count.** |

**A pump requires** a significant upward move — `market_cap_change_pct ≥ 50%`
**OR** `price_change_pct ≥ 40%` — **AND** total qualifying signals across the
four ≥ `minimum_confirmation_signals` (2). A lone price move (one signal) is
**not** recorded.

### Detection score (0–100)

Deterministic **strength** score — **not a probability, not a prediction**.
Weights sum to 100 (`market_cap 35 / price 30 / volume 20 / transactions 15`);
each component saturates at 2× its threshold; a rapid acceleration adds ≤ 15.

### Confidence (deterministic)

| | Rule |
|---|---|
| **high** | a *strong* move (≥ `strong_move_multiplier` × threshold, default 1.5×) **and** both the volume **and** transaction ratios confirm |
| **medium** | a move clears its threshold **and** exactly one activity ratio confirms |
| **low** | detected but weak — e.g. market-cap + price only with no activity confirmation, or a marginal move |

### Event lifecycle

- `started_at` / `start_market_cap` / `start_price_usd` — the trough observation.
- `peak_at` / `peak_market_cap` / `peak_price_usd` — the highest-MC observation.
- `ended_at` / `status` — `active` (`ended_at` null) while the peak IS the most
  recent observation; `completed` once a lower observation follows the peak.
- `duration_minutes` — `started_at → peak_at`.
- **Merging** — a new detection overlapping the token's most recent event within
  `event_merge_window_minutes` (60) updates that event (earliest start, highest
  peak, higher score, higher confidence) instead of creating a duplicate. One
  continuous pump = one row.
- **Repeated pumps** — a genuinely separate move (outside the merge window) is a
  **new** `pump_events` row; earlier events are never overwritten.
- **Stale sweep** — an `active` event whose `peak_at` is older than
  `event_stale_after_minutes` (90) and is no longer detected → `completed`
  (`ended_at` = the token's last observation).

### `pump_events` table

`id, token_id, started_at, peak_at, ended_at (nullable), start_market_cap,
peak_market_cap, start_price_usd, peak_price_usd, market_cap_change_pct,
price_change_pct, volume_h24_change_ratio, txns_h24_change_ratio,
duration_minutes, detection_score (0–100), confidence (low|medium|high),
status (active|completed), timestamps`. Indexes: `(token_id, started_at)`,
`status`. **No evidence / catalyst records** — that is Step 16B/16C. Missing
values are `null`, never `0`.

### Command + schedule

```bash
docker compose exec backend php artisan memecoins:detect-pumps
```

```
Pump detection completed.

Tokens analyzed:            90
Pump events created:        5
Pump events updated:        0
Completed by stale sweep:   0
Active events:              2
Completed events:           3
```

Scheduled (`routes/console.php`, reusing the existing `scheduler` container):
`memecoins:detect-pumps` on `5,15,25,35,45,55 * * * *` — the **same cadence as
discovery, offset ~5 min** so it always runs *after* ingestion has written the
latest snapshots. `withoutOverlapping(15)`.

### Query bounding (no N+1)

One query for recently-observed token ids → **one window-function query**
(`ROW_NUMBER() OVER (PARTITION BY token_id …)`) for the most recent
`recent_snapshots_per_token` (24) snapshots of all of them → one token load →
per-detected-event create/merge. A token's full snapshot history is never
loaded.

Config: [`config/pump.php`](../backend/config/pump.php). **The thresholds are
initial heuristic MVP defaults — conservative, easy to tune, not claimed to be
statistically optimal.**

### Evidence collection (Step 16B) builds on this

Once an event exists, the **Evidence Engine** collects timestamped FACTS present
around it — observed market behaviour, stored token metadata, preceding
related-token moves, and (the only external call) GDELT news — stored in the
`evidences` table, one `PumpEvent hasMany Evidence`. It records facts like
*"an article was published 12 minutes before the observed pump peak"* and
**never** asserts that anything caused the pump. See
[`docs/evidence-engine.md`](evidence-engine.md).

### AI explanation (Step 16C) is the final layer

`memecoins:explain-pumps` sends one `PumpEvent` + its ranked evidence to the
configured LLM provider (abstracted behind `PumpExplanationProvider`;
`config/ai.php`) and stores a structured, **evidence-grounded** interpretation in
`pump_explanations` (`PumpEvent hasOne PumpExplanation`). Every claim cites
evidence ids; causal language is rejected; `UNKNOWN` is returned when evidence is
thin or conflicting. The read API exposes it under `data.pump_intelligence` and
**never** triggers generation. See
[`docs/pump-explanation.md`](pump-explanation.md).

### Final pipeline

```
DexScreener → MarketSnapshots → PumpEvent (16A) → Evidence (16B) → AI Explanation (16C)
                                     detect-pumps   collect-evidence   explain-pumps
   scheduler:                :00/:10        :05/:15          :08/:18          :09/:19
```

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
| `selected_for_enrichment`, `candidate_cap_dropped`, `search_terms_used`, `search_terms_with_results` | Step 14 coverage counts |
| `chains_discovered` | JSON `{ <chain_id>: <unique candidate count> }` — served by `GET /api/memecoins/discovery-status` |
| `error_message` | concise message (≤ 480 chars, no stack trace) on failure |

- The HTTP response echoes `meta.ingestion_run_id` and `meta.retrieved_at`.
- `GET /api/memecoins/discovery-status` reads these columns for the latest run —
  PostgreSQL only, never calls DexScreener.
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

**`GET /api/memecoins`** — read-only. It **never** calls DexScreener, CoinGecko
or GeckoTerminal, never writes, never runs discovery. Query params: `?chain=<id>`
(any chain id; the frontend dropdown is just a convenience list), `?limit=`
(default 20, server max 50). Qualification (Step 13C, refined in Step 19): `earliest_pair_created_at`
within `max_age_days` of now **AND** a **verified / observed market cap** has ever
peaked **inside the `$5M`–`$200M` band** — via `observed_peak_market_cap >= $5M`
(`CURRENT_OBSERVATION`) **or** `historical_peak_status = HISTORICAL_VERIFIED` with
`historical_peak_value >= $5M`, **AND**
`GREATEST(observed_peak_market_cap, historical_peak_value) <= $200M`.
**`HISTORICAL_ESTIMATE` (FDV basis) and `UNKNOWN` are excluded** — an FDV estimate
is not a market cap. A token whose verified/observed peak ever exceeded `$200M`
is **excluded even if its current market cap has fallen back into the band** — we
do not re-qualify on current MC. A token that dumped *below* `$5M` after an
in-band peak **stays qualified** (the floor is a peak rule). Default sort:
`GREATEST(observed_peak_market_cap, historical_peak_value)` DESC.
`?sort=recent_crossing` (Step 20) re-orders by the token's representative
"$5M crossing" timestamp, newest first, no-crossing tokens last — the default
stays `peak_market_cap` (see [qualification-events.md](qualification-events.md)).
No momentum scoring.

Response:

```jsonc
{
  "data": [
    {
      "id": 55, "chain_id": "solana", "token_address": "…",
      "name": "Dogecoin", "symbol": "DOGE",
      "current_market_cap": 74613000,          // from the LATEST market_snapshots row
      "observed_peak_market_cap": 74613000,     // OUR OWN snapshot peak (tokens row)
      "observed_peak_market_cap_at": "2026-08-28T07:13:03+00:00",

      // How this token qualifies — always a verified/observed market cap,
      // kept DISTINCT from observed_peak_market_cap.
      "qualification_status": "HISTORICAL_VERIFIED",    // CURRENT_OBSERVATION | HISTORICAL_VERIFIED only
      "qualification_peak_value": 8200000,             // a real market cap, never an FDV estimate
      "qualification_peak_at": "2026-08-26T05:00:00+00:00",
      "qualification_source": "coingecko",             // dexscreener | coingecko
      "qualification_basis": "market_cap",             // current_market_cap | market_cap

      // Step 20 — the representative "$5M crossing" (verified over observed).
      "qualification_crossed_at": "2026-08-24T11:00:00+00:00",   // null if not yet recorded
      "qualification_crossing_type": "HISTORICAL_VERIFIED",       // CURRENT_OBSERVATION | HISTORICAL_VERIFIED | null
      "recently_crossed": false,                        // crossed_at within recent_crossing_hours (48)

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
    "sort": "peak_market_cap",                // peak_market_cap | recent_crossing
    "recent_crossing_hours": 48,
    "filters": {
      "max_age_days": 30,
      "observed_peak_market_cap_min_usd": 5000000,
      "observed_peak_market_cap_max_usd": 200000000
    }
  }
}
```

### `GET /api/memecoins/recently-crossed` (Step 20)

Read-only, **PostgreSQL only** — never DexScreener / CoinGecko / GeckoTerminal,
never writes, never creates a crossing event. Returns currently-**qualified**
tokens (age ≤ 30d, verified/observed peak in `[$5M, $200M]`) whose
**representative** crossing landed within the window (default 48h, `?hours=`
1…168, optional `?chain=`), newest crossing first. A token with current MC
**below `$5M`** still appears — it previously crossed. Rows carry `ACTIVE`
(current MC ≥ $5M) / `COOLED` (< $5M). See
[qualification-events.md](qualification-events.md).

```jsonc
{
  "data": [
    { "symbol": "MEME", "chain_id": "solana", "current_market_cap": 4200000,
      "observed_peak_market_cap": 7200000, "qualification_peak": 7200000,
      "crossed_at": "…", "crossing_type": "CURRENT_OBSERVATION",
      "crossing_market_cap_value": 5100000, "status": "COOLED", "age_days": 2.1 }
  ],
  "meta": { "hours": 48, "count": 1, "source": "postgresql" }
}
```

Every row here is qualified on a **verified/observed market cap** —
`qualification_status` is always `CURRENT_OBSERVATION` or `HISTORICAL_VERIFIED`
and `qualification_basis` is always `current_market_cap` or `market_cap`, never
`fdv_total_supply`. When no evidence row exists yet but
`observed_peak_market_cap >= $5M`, the resource derives `CURRENT_OBSERVATION`
(and falls back to the mirrored columns for `HISTORICAL_VERIFIED`).

**Query strategy (no N+1):** `Token::latestSnapshot()` is a
`hasOne(...)->latestOfMany('observed_at')` relation; `historicalPeakEvidence()`
is a `hasOne`. Both are eager-loaded with `->with([...])`. One query for the
filtered/sorted tokens, one window-function subquery join for their latest
snapshots, one for their evidence rows — **≤ 3 queries total, independent of row
count**.

**Why the frontend never calls DexScreener:** a discovery run takes 15–30 s and
hits rate-limited endpoints. The browser must stay fast and must not multiply
provider load per viewer. The dashboard therefore only reads already-persisted
observations through Laravel; ingestion is the scheduler's job.

**Dashboard** (`frontend/`, React + TS + Vite):
`src/api/memecoins.ts` (fetch, typed, abortable) · `src/types/memecoin.ts` ·
`src/lib/format.ts` (`$74.6M`, `8d`, timestamps) ·
`src/components/{MemecoinTable,ChainFilter,RecentlyCrossedSection}.tsx` ·
`src/App.tsx`.
Two sections (Step 20): **🔥 Recently Crossed $5M** (compact card list —
Token / Chain / Crossed / Current MC / Peak MC / `ACTIVE`|`COOLED`, from
`GET /api/memecoins/recently-crossed`) above the existing **30-Day Qualified
Memecoins** table (from `GET /api/memecoins`, with a Sort control:
Peak market cap / Recent crossing). States: loading / ready / empty / error
("Unable to load memecoin data.", no stack traces). Chain dropdown re-queries
the API. Manual **Refresh** button plus a gentle 60 s auto-refresh (one call per
feed per tick — no aggressive polling). Footer shows `Data source: DexScreener`,
last-observed time, the note *"Observed peak reflects the highest market cap
captured by this detector, not guaranteed lifetime history"* (never "ATH"), and
*"a token below $5M now can still be listed if it previously crossed"*.

---

## Token Detail View

A read-only drill-down for a single token. Same data contract as the dashboard —
PostgreSQL only, **never** DexScreener, never a write, never a snapshot.

### Route (frontend)

```
/                                 → dashboard ("30-Day Leaders")
/memecoin/:chainId/:tokenAddress   → token detail
```

Client-side routing via **React Router** (`react-router-dom`). Identity in the URL
is always `chainId` + `tokenAddress` — **never the symbol**. Both segments are
`encodeURIComponent`-encoded when links are built and decoded by the router on
read. Example: `/memecoin/solana/Ci11wAJVj4tMeBo4EJUUKNnejAvHorcktcMSHmSLQdx4`.

On the dashboard, a table row is a link to its detail page (`role="link"`,
keyboard-activatable). The per-row **copy contract address** button stops event
propagation, so copying never triggers navigation.

### Read API — `GET /api/memecoins/{chainId}/{tokenAddress}`

- Reads PostgreSQL only. Never calls DexScreener, never writes, never creates
  snapshots, never mutates state.
- **Identity is `(chain_id, token_address)`.** `chain_id` is matched
  case-insensitively; `token_address` is matched exactly first (Solana base58 is
  case-sensitive) then case-insensitively (checksum-cased EVM addresses).
  A symbol never resolves.
- Route params are constrained (`chainId` `[A-Za-z0-9_-]{1,64}`, `tokenAddress`
  `[A-Za-z0-9._:-]{1,128}`). A miss → `404 {"error": "Memecoin not found."}` —
  a clean JSON body, never an internal exception.
- Never calls **CoinGecko** or **GeckoTerminal** either — the historical
  qualification evidence is read from the stored `historical_peak_evidences` row,
  not re-fetched.
- **Dashboard qualification is NOT applied here.** A token that has since fallen
  below $5M current MC, or aged past 30 days, or was never qualified at all, is
  still viewable as long as the `Token` row exists. Qualification gates the
  *list*, not *existence*.
- **Query strategy (no N+1):** token + its `historicalPeakEvidence` +
  `qualificationEvents` + `narrativeReport.sources` (eager) + one bounded window
  of recent snapshots + recent pump events (`explanation`, `evidences` eager) —
  **≤ 10 queries**, the first snapshot row is the latest observation. The full
  `market_snapshots` history is **never** loaded.

Response (Step 15 — nested; Step 17-fix adds `historical_estimate`;
Step 20 adds `qualification_timeline`):

```jsonc
{
  "data": {
    "id": 55,
    "chain_id": "solana", "token_address": "…", "name": "Dogecoin", "symbol": "DOGE",
    "age_days": 8.4,                        // now − earliest_pair_created_at — nullable

    "qualification": {                      // MAIN-LIST qualification (verified/observed MC only)
      "status": "HISTORICAL_VERIFIED",      // CURRENT_OBSERVATION | HISTORICAL_VERIFIED | HISTORICAL_ESTIMATE | UNKNOWN
      "qualified": true,                    // true ONLY for CURRENT_OBSERVATION / HISTORICAL_VERIFIED ≥ $5M
      "peak_value": 11900000,               // a verified/observed market cap; null for HISTORICAL_ESTIMATE & UNKNOWN
      "peak_at": "…",
      "source": "coingecko",                // dexscreener | coingecko | null
      "basis": "market_cap",                // current_market_cap | market_cap | null
      "confidence": "high"                  // high | medium | low | null
    },

    "qualification_timeline": {             // Step 20 — WHEN / HOW it crossed $5M
      "crossed_at": "2026-08-24T11:00:00+00:00",   // representative crossing; null if none recorded
      "crossing_type": "HISTORICAL_VERIFIED",       // CURRENT_OBSERVATION | HISTORICAL_VERIFIED | null
      "crossing_source": "coingecko",               // dexscreener | coingecko | null
      "crossing_market_cap_value": 6100000,
      "recently_crossed": false,
      "currently_below_threshold": true,            // latest MC < $5M (still qualified) | null
      "threshold_usd": 5000000,
      "events": [ { "type": "…", "crossed_at": "…", "source": "…", "market_cap_value": 0 } ]
    },

    "historical_estimate": null,            // or, for a HISTORICAL_ESTIMATE token, an
                                            // explicitly-named FDV block (see below) —
                                            // informational only, never a market cap

    "observed": {                           // OUR OWN snapshots — never merged with qualification
      "peak_market_cap": 2980000,           // may differ from qualification.peak_value for cold-start tokens
      "peak_at": "…", "first_observed_at": "…", "last_observed_at": "…"
    },

    "latest": {                             // most recent MarketSnapshot — every field nullable
      "market_cap": 2100000, "price_usd": "…", "fdv": 2200000,
      "liquidity_usd": 1200000, "volume_h24": 400000, "price_change_h24": 3.2,
      "txns_h24": 3000, "buys_h24": 1800, "sells_h24": 1200,
      "primary_dex_id": "raydium", "primary_pair_address": "…", "observed_at": "…"
    },

    "pair": { "earliest_pair_created_at": "…", "pair_count": null },  // pair_count not captured → always null

    "snapshots": [                          // newest first, capped at 50
      { "observed_at": "…", "price_usd": "…", "market_cap": 2100000, "fdv": 2200000,
        "liquidity_usd": 1200000, "volume_h24": 400000, "price_change_h24": 3.2,
        "txns_h24": 3000, "buys_h24": 1800, "sells_h24": 1200 }
    ],

    "pump_intelligence": { "events": [ /* Step 16C — see pump-explanation.md */ ] },

    "token_narrative": {                     // Step 21 — token-level origin + popularity
      "status": "completed | partial | failed | pending",
      "generated_at": "…", "model_provider": "anthropic",
      "origin": {
        "status": "completed", "headline": "…", "summary": "Project materials describe…",
        "origin_type": "ANIMAL_MEME",        // fixed enum incl. UNKNOWN
        "supporting_facts": [ { "statement": "…", "source_ids": [1, 2] } ],
        "confidence": "medium", "caveats": [], "unknowns": []
      },
      "popularity": {
        "status": "completed", "headline": "…", "summary": "Contemporary reports show…",
        "timeline": [ { "date": "2026-08-19"|null, "title": "…", "description": "…",
                        "type": "EXCHANGE_LISTING", "source_ids": [12], "confidence": "high" } ],
        "dominant_factors": ["…"], "confidence": "medium", "caveats": [], "unknowns": []
      },
      "sources": [ { "id": 1, "section": "origin", "source_type": "official",
                     "source_name": "…", "title": "…", "source_url": "…",
                     "published_at": "…"|null, "confidence": "high", "claim": "…",
                     "relevance_score": 55 } ]
    },
    // A non-`completed` section returns only `status` + null body. Provider
    // error details are NEVER exposed. The GET never triggers research.

    "provenance": {
      "data_source": "dexscreener",
      "last_observed_at": "…",
      "historical_qualification_source": "coingecko",
      "observed_peak_note": "Observed peak is the highest market cap captured by this detector …",
      "historical_estimate_note": null      // non-null only when data.historical_estimate is present
    }
  },
  "meta": { "retrieved_at": "…", "recent_snapshot_limit": 50, "recent_pump_event_limit": 10, "observed_peak_note": "…" }
}
```

For a `HISTORICAL_ESTIMATE` token, `data.historical_estimate` is:

```jsonc
"historical_estimate": {
  "estimated_fdv_usd": 11900000,     // FDV = peak price × total supply — NOT a market cap
  "estimate_source": "geckoterminal",
  "estimate_basis": "fdv_total_supply",
  "estimate_confidence": "medium",
  "estimate_at": "…",
  "note": "Estimated historical FDV ≥ $5M … This does NOT verify that market capitalization reached $5M, and does not qualify the token for the main list."
}
```

There is **no** `historical_market_cap` key anywhere. Missing values are JSON
`null`, **never coerced to `0`**. `qualification.qualified` is `true` only for
`CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` clearing $5M; when there is no
evidence row it is derived — `CURRENT_OBSERVATION` if
`observed_peak_market_cap ≥ $5M`, else `UNKNOWN`. **`UNKNOWN` means "not
verified", never "did not reach $5M".**

### Observed peak vs qualification peak vs FDV estimate

Three figures, **reported separately and never merged**:

- **`observed.peak_market_cap`** — highest market cap OUR snapshots have captured
  since `first_observed_at`.
- **`qualification.peak_value`** — a **verified / observed market cap** that
  qualifies the token for the main list (`CURRENT_OBSERVATION` or CoinGecko
  `HISTORICAL_VERIFIED`). Never an FDV estimate; `null` for `HISTORICAL_ESTIMATE`.
- **`historical_estimate.estimated_fdv_usd`** — a GeckoTerminal **FDV** estimate
  (peak price × total supply). Informational only, explicitly labelled, never
  called a market cap, and it does **not** put the token on the main list.

For a cold-start recovered token these differ, e.g. observed `$2.98M` vs
qualification `$11.9M`. The read API never writes either value.

### Detail page sections

| Section | Notes |
|---|---|
| Header | name, symbol · chain, contract address **visually middle-truncated** (`Ci11w…Qdx4`), copy button, `← Back to 30-Day Leaders` |
| **Live market chart** (Step 17) | embedded **DexScreener** chart `<iframe>` built from `chain_id` + `latest.primary_pair_address` (see *Live DexScreener Chart* below). Null pair → "Live chart unavailable." — never a broken iframe. |
| Market overview | stat cards: Current MC, **Observed Peak MC**, **Qualification Peak** (verified/observed MC, or "Not verified"), Age, 24h Volume, Liquidity, and — only when one exists — **Historical FDV estimate** ("Informational — not a market cap"). A note explains the difference between the figures. |
| **Why is this token on the list?** | status-coloured evidence card. **Qualified** (`CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED`): verified peak MC / source / basis / confidence. **`HISTORICAL_ESTIMATE`** (not qualified): "Not in the main $5M list — FDV estimate only", the FDV figure, and "**FDV = peak price × total supply** … not a verified circulating market cap. It does not verify that market capitalization reached $5M." **`UNKNOWN`**: "could not be verified with available data" — **never** "never reached $5M". |
| **Qualification timeline** (Step 20) | *Crossed $5M* (timestamp), *Crossing type* ("Current observation" / "Historically verified crossing"), *MC at crossing*, *Current MC*, *Peak MC*, *Within recent window*. If the current MC is below $5M: *"Current MC is below $5M, but the token remains qualified because it previously crossed the threshold."* — never "currently above the threshold". Shows all recorded crossings when both types exist. Placeholder when none recorded (**not** "never reached $5M"). |
| **Pump events** (Steps 16A–C) | timeline of recent `PumpEvent`s: `started_at → peak_at`, MC %, price %, detection score, detection confidence, status. Each expands (`<details>`) to its persisted AI **"Why did this coin pump?"** explanation — most-supported catalyst, summary, cited evidence (expandable), AI confidence, caveats, unknowns. `pending` → "Explanation pending."; `failed` → "Explanation unavailable." (no provider error shown); `UNKNOWN` → "No verified catalyst was established…". **Never calls AI from the browser.** |
| Market activity | price, current MC, FDV, liquidity, 24h volume, 24h price change, 24h transactions, buys, sells, DEX, primary pair. Null → **"Unavailable"**. |
| Observation history | dependency-free market-cap sparkline + a table (Observed At / Price / Market Cap / FDV / Volume / Liquidity / Transactions), newest first, ≤ 50 rows |
| Token identity | chain, contract address (+ copy), name, symbol, pair count (`Unavailable`), earliest pair created at, first/last observed at, data source |
| **Token narrative intelligence** (Step 21) | Two-column, evidence-grounded block: **"Why it became popular"** (headline / summary / chronological timeline / dominant factors / confidence / sources) and **"Why it was created"** (origin type / headline / summary / supporting facts / confidence / sources). Stacks vertically on mobile. Each factual line cites `token_narrative_sources` ids (expandable). `pending` / `partial` / `failed` show a neutral note, never a stack trace. **No inferred creator intent, no causality from market timing.** See [token-narrative-intelligence.md](token-narrative-intelligence.md). |
| Data provenance | data source, latest observation, historical-qualification source, observed-peak note, FDV-basis note when applicable, and the network statement: our JS only calls this app's Laravel API; the **only** third-party content is the embedded DexScreener chart iframe. |

The dashboard table gains a compact **CURRENT / VERIFIED** badge per row (in the
Token cell) — `HISTORICAL_ESTIMATE` tokens are never on the dashboard — and keeps
its clickable rows + per-row copy button.

### Live DexScreener Chart (Step 17)

The detail page embeds a **DexScreener** price chart as an `<iframe>`.

- **Third-party & visual-only.** The chart is served directly by
  `dexscreener.com` and updates itself. It is completely independent of our
  stored API data — it is not a source for any persisted value.
- **URL construction.** `https://dexscreener.com/{chain_id}/{primary_pair_address}?embed=1&theme={light|dark}&trades=0&info=0`,
  built **only** from values our own detail API returned:
  `data.chain_id` and `data.latest.primary_pair_address`. Both are format-checked
  in React (`chain` slug regex; pair = `0x`+40 hex **or** 32–64 base58) before
  the iframe renders. The token **contract address is never used** as the pair.
  No arbitrary URL is ever accepted from the browser / query string.
- **Primary pair = highest liquidity.** `primary_pair_address` is the token's
  pair with the highest `liquidity.usd`, selected deterministically by
  `DexScreenerNormalizer::representativePair()` during ingestion (lexicographically
  smallest `pairAddress` as the tie-break when no pair reports liquidity). The
  chart therefore shows the same representative pair the rest of the page
  describes.
- **Null pair → no iframe.** If we have never recorded a `primary_pair_address`
  (or it fails the format check), the section shows "Live chart unavailable."
- **iframe safety.** `https` only, `title="DexScreener live chart"`,
  `loading="lazy"`, `referrerPolicy="no-referrer"`, responsive container
  (`aspect-ratio`, ~420–640px desktop, full-width `68vh` on mobile).
- **Network exception.** This is the single documented exception to
  *browser → our Laravel API only*. Our JavaScript makes **no** requests to
  `api.dexscreener.com` (or any DexScreener host) — only the `<iframe>` element
  loads DexScreener content, as an embedded third-party visualization. Verified
  by inspecting network traffic: app `fetch()` calls go to `localhost:8010`
  only; the sole `dexscreener.com` request is the iframe document.

### Pump / narrative intelligence — now implemented

"Why did this coin pump?" is the persisted, evidence-grounded AI explanation from
Steps 16A–16C (see [pump-detection.md](pump-detection.md),
[evidence-engine.md](evidence-engine.md), [pump-explanation.md](pump-explanation.md)).

**"Why was this coin created?" and "Why did it become popular?"** are the
token-level narrative syntheses of Step 21 (`data.token_narrative`) — an
evidence-grounded interpretation of collected `token_narrative_sources` + our own
stored `Evidence` / market history. Every factual claim cites source ids; the
model never browses, never invents sources / URLs / dates, never asserts creator
intent, and never treats market timing as causation. Generation is
CLI/scheduler-only (`memecoins:research-narratives`, hourly) — the read API never
triggers it. See [token-narrative-intelligence.md](token-narrative-intelligence.md).
Neither section generates anything in the browser.

### Frontend files

`src/api/memecoinDetail.ts` (typed, abortable, `MemecoinNotFoundError` for 404) ·
`src/types/memecoinDetail.ts` (`MemecoinDetail` / `MemecoinQualification` /
`MemecoinLatestSnapshot` / `MemecoinSnapshot` / …) · `src/types/memecoin.ts`
(+ `qualification_*` for the badge) · `src/pages/{Dashboard,MemecoinDetail}.tsx` ·
`src/components/{CopyAddress,MarketCapSparkline,QualificationBadge}.tsx` ·
`src/components/MemecoinTable.tsx` (row → detail navigation + badge) ·
`src/lib/qualification.ts` (status → label/icon/tone maps — no invented text) ·
`src/lib/format.ts` (`truncateMiddle`, `formatInteger` / `formatPercent` /
`formatPercentCompact` / `formatPrice`). States: `loading` / `ready` / `error` /
`not-found`. The browser only ever *calls* this app's Laravel API; the only
third-party content is the embedded DexScreener chart `<iframe>` (Step 17).

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
      "sources": ["trending_meta", "profile", "boost"],
      "discovery_context": {                  // null unless surfaced via trending meta
        "trending_meta_slug": "chinese",
        "trending_meta_name": "Chinese",
        "trending_meta_count": 2
      },
      "data_source": "dexscreener",
      "retrieved_at": "2026-08-28T12:00:00+00:00"
    }
  ],
  "meta": {
    "count": 1,
    "limit": 20,
    "chain": null,
    "filters": {
      "max_age_days": 30,
      "observed_peak_market_cap_min_usd": 5000000,
      "observed_peak_market_cap_max_usd": 200000000
    },
    "coverage_note": "Trending-meta-first sample (documented DexScreener meta APIs); activity feeds + optional keyword fallback; not an exhaustive token census.",
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

### `qualification_events` — "$5M crossing" (Step 20)

One row per token per `type` (`CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED`),
**unique on `(token_id, type)`**: `crossed_at`, `threshold_usd`,
`evidence_status`, `source`, `market_cap_value`. Written only by the pipeline
(`QualificationEventRecorder`), idempotent, `crossed_at` never rewritten.
`historical_peak_evidences` also gains a nullable `first_verified_crossing_at`.
See [qualification-events.md](qualification-events.md).

### `token_narrative_reports` / `token_narrative_sources` — narrative intelligence (Step 21)

`token_narrative_reports` — one row per token (`unique(token_id)`): `origin_status`
/ `origin_summary` / `origin_explanation_json`, `popularity_status` /
`popularity_summary` / `popularity_explanation_json`, `overall_status` /
`overall_confidence`, `research_started_at` / `research_completed_at` /
`generated_at`, `model_provider` / `model_name` / `research_providers_used`,
`error_message` (concise, no stack traces). `token_narrative_sources` — one row
per cited source (`unique(token_narrative_report_id, dedupe_hash)`): `section`,
`source_type`, `source_name`, `source_url`, `title`, `published_at` (real or
null), `accessed_at`, `claim`, `relevance_score`, `confidence`, `provider`. The
narrative JSON references these rows by `id`. Written only by
`memecoins:research-narratives`; sources are persisted **before** the AI call.
See [token-narrative-intelligence.md](token-narrative-intelligence.md).

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
| `Services\DexScreener\DexScreenerDiscoveryService` | The pipeline. Age is the only pre-persistence gate; qualification is by verified/observed market cap (`CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED`) — `HISTORICAL_ESTIMATE` and `UNKNOWN` do not qualify. Builds diagnostics. |
| `Services\DexScreener\DexScreenerNormalizer` | Pure: one raw pair list → one `TokenCandidateData`. Time injected. |
| `Services\DexScreener\TokenObservationService` | `DB::transaction`: find-or-create `Token` on `(chain_id, token_address)`, refresh name/symbol/earliest_pair_created_at, append a `MarketSnapshot`, raise `observed_peak_market_cap`. |
| `Services\DexScreener\RecordedObservation` | `{ token, snapshot, tokenWasCreated, peakUpdated, previousObservedPeak }`. |
| `Services\DexScreener\DiscoveryResult` | `{ candidates, diagnostics, notQualifiedSample }`. |
| `DTOs\DexScreener\TokenCandidateData` | Immutable normalized current observation. |
| `DTOs\DexScreener\QualifiedCandidate` | `TokenCandidateData` + persisted peak figures → `toArray()` = the API item. |
| `Services\Historical\QualificationEventRecorder` | Step 20 — upserts `qualification_events` rows for tokens whose evidence proves a verified/observed crossing in `[$5M, $200M]`. One batch pre-load query; idempotent. Pipeline-only. |
| `Http\Controllers\Api\RecentlyCrossedController` | Step 20 — `GET /api/memecoins/recently-crossed`. Read-only, PostgreSQL only. |
| `Services\Narrative\NarrativeResearchService` | Step 21 — orchestrates one narrative run: collect sources (origin + popularity) via `NarrativeResearchProvider`s, rank + persist them, ask the `NarrativeExplanationProvider`, validate each section independently, persist the report. Cooldown / partial / provider-failure isolation. Command-only. |
| `Services\Narrative\{TokenOriginResearchService,TokenPopularityResearchService,NarrativeSourceRanker,NarrativeEvidenceRecorder,NarrativeExplanationService,NarrativeExplanationValidator}` | Step 21 support — source collection, quality tiering, idempotent persistence, AI call + validation. |
| `Services\Narrative\Providers\{InternalEvidenceResearchProvider,GdeltNarrativeResearchProvider,AnthropicNarrativeExplanationProvider,NullNarrativeExplanationProvider}` | Step 21 providers — the always-on internal baseline, token-level GDELT, and the swappable AI vendor (chosen by `NARRATIVE_AI_PROVIDER`, separate binding). |

Config: [`config/dexscreener.php`](../backend/config/dexscreener.php). The DexScreener
base URL is **always** `config('dexscreener.base_url')` ← `DEXSCREENER_BASE_URL`
— never hardcoded in business logic.

---

## Discovery sources (Step 19 — trending-meta-first)

**Priority order.** Trending-meta discovery is the primary source; keyword
search is a fallback, OFF by default.

| # | Source | Endpoint(s) | Contributes | `sources` tag | Default | Toggle |
|---|---|---|---|---|---|---|
| 1 | **trending meta** | `/metas/trending/v1` → `/metas/meta/v1/{slug}` | full member pairs (market data) — **pre-filtered** before enrichment | `trending_meta` | **ON** | `DEXSCREENER_TRENDING_META_ENABLED` |
| 2 | latest token profiles | `/token-profiles/latest/v1` | `chainId` + `tokenAddress` | `profile` | ON | `DEXSCREENER_PROFILES_ENABLED` |
| 3 | latest + top boosts | `/token-boosts/latest/v1`, `/token-boosts/top/v1` | `chainId` + `tokenAddress` | `boost` | ON | `DEXSCREENER_BOOSTS_ENABLED` |
| 4 | keyword search (**fallback**) | `/latest/dex/search?q=` | `baseToken.address` + `chainId` per returned pair | `search` | **OFF** | `MEMECOIN_KEYWORD_DISCOVERY_ENABLED` |

Multi-source hits union their `sources` tags (deduped, order preserved).

### Why trending-meta, not the real Trending table

DexScreener's live per-pair *Trending* table
(*Trending — 5M / 1H / 6H / 24H*) is fed by an **undocumented WebSocket**
(`wss://io.dexscreener.com/dex/screener/v7/pairs/...?rankBy[key]=trendingScoreH6`)
that is behind **Cloudflare bot management**, sends **binary frames**, is
**version-tagged** (breaks without notice), and is **not supported** by
DexScreener. It fails *free / no key / no scraping / no browser automation /
maintainable*. Full analysis: [trending-discovery-reconnaissance.md](trending-discovery-reconnaissance.md).

Instead we use the **documented** `/metas/trending/v1` → `/metas/meta/v1/{slug}`
pair — the same **trending narratives** the DexScreener homepage narrative bar
shows (in the same order), keyless and free. It is an **approximation** of
per-pair trending: a token that is trending but tagged into none of the ~18
narratives is missed; keyword search (the fallback) widens the long tail.

### Trending Meta discovery flow

```
GET /metas/trending/v1            → ≤ DEXSCREENER_TRENDING_META_LIMIT (18) slugs
GET /metas/meta/v1/{slug}  ×N     → full member pairs (chainId, baseToken, pairAddress,
                                    marketCap, fdv, liquidity, volume, priceChange, txns,
                                    dexId, pairCreatedAt, info)
```

- Each surviving pair → a discovery candidate with `source = trending_meta` and a
  small `discovery_context` block `{ trending_meta_slug, trending_meta_name,
  trending_meta_count }`. Multiple metas surfacing one token union to a single
  `trending_meta` source; `trending_meta_count` records how many.
- **The paid narrative-bar ad is ignored.** The documented `/metas/*` responses
  never carry it; a defensive guard also drops any non-pair entry
  (`trending_meta_ad_or_malformed_skipped`).

### Pre-filter (before enrichment)

Because meta pairs already carry market data, candidates are filtered **before**
the expensive `/token-pairs/v1` enrichment. Drop if any of:

| # | Check | Drop reason |
|---|---|---|
| 1 | `marketCap` present and `> 0` | `market_cap_missing_or_zero` |
| 2 | `marketCap <= $200M` (`MEMECOIN_OBSERVED_PEAK_MAX_USD`) | `market_cap_above_ceiling` |
| 3 | `liquidity.usd > 0` | `liquidity_zero` |
| 4 | `volume.h24 > 0` | `volume_zero` |
| 5 | `pairCreatedAt` present | `pair_created_at_missing` |
| 6 | this pair's age `<= 35 days` (`MEMECOIN_PREFILTER_MAX_AGE_DAYS`) | `loose_age_exceeded` |

**The `>= $5M` lower bound is NOT a pre-filter** — a token may currently be
below $5M yet have qualified earlier (or via historical evidence). The floor is a
qualification-step peak rule.

The loose 35-day check is **performance only**. Final age validation always uses
`earliest_pair_created_at` = `min(pairCreatedAt)` across **all** of the token's
pairs from full enrichment (a new pool on an old token still fails). `pairCreatedAt`
is DEX pool creation time, never "token creation date".

### Representative pair

Unchanged: the token's pair with the **highest `liquidity.usd`** (deterministic
lexical-smallest `pairAddress` fallback).

### Candidate prioritization (deterministic, market cap is NOT a signal)

1. surfaced by a trending meta at all
2. number of **distinct** trending metas that surfaced it
3. profile signal present
4. boost signal present
5. keyword-search occurrence count
6. profile freshness (list position) — stable secondary tie-break
7. `token_key` ascending — total, stable final tie-break

Paid boost feeds can never out-priority organic trending-meta evidence.

---

## Discovery Coverage Strategy (Step 14, refined in Step 19)

### Why DexScreener cannot provide exhaustive discovery

There is **no universal "all newly launched tokens" endpoint**
(`dexscreener-reconnaissance.md §6`). `/latest/dex/search` needs a `q`, returns
≤ 30 rows, has no pagination and no chain filter. The activity feeds
(`/token-profiles/*`, `/token-boosts/*`) list tokens whose owners **paid**
DexScreener. Even the trending-narrative feed is ~18 DexScreener-curated metas.
So discovery is a **trending-narrative + activity sample**, never a census —
"maximize relevant trending memecoins discovered per unit of API budget", not
"discover everything".

### Keyword search-term engine (`SearchTermEngine`) — fallback only

> **Step 19:** keyword discovery is a **supplemental long-tail fallback**, OFF by
> default (`MEMECOIN_KEYWORD_DISCOVERY_ENABLED=false`). It never overrides
> trending-meta discovery. `SearchTermEngine` is only consulted when it is
> enabled. It is kept, not deleted.

Deterministic and reproducible (no rotation / randomness). Builds one term list
per run by priority:

| # | Category | Source | Config |
|---|---|---|---|
| 1 | core meme terms | curated list | `MEMECOIN_SEARCH_TERMS` (default `pepe,doge,cat,dog,frog,wif,inu,meme,shib,bonk,elon,ai,trump,politics,animal`) |
| 2 | trending meta **slugs** | `/metas/trending/v1` | up to `DEXSCREENER_TRENDING_META_TERMS` (default 8) |
| 3 | trending meta **names** | `/metas/trending/v1` | same cap |
| 4 | ecosystem terms | curated list | `MEMECOIN_ECOSYSTEM_TERMS` (default `solana,base,ethereum,bsc,arbitrum`) — **supplementary signals only, NOT chain filters** (search is global) |

Terms are lowercased, trimmed, de-duplicated **across categories**, and the
merged list is truncated to **`MEMECOIN_SEARCH_TERM_BUDGET`** (default **25**).
`meta.diagnostics.search_term_categories` reports how many of each category
survived.

### Candidate cap vs enrichment cap vs result limit — three separate ceilings

| Ceiling | Config | Default | What it bounds |
|---|---|---|---|
| discovery candidate cap | `MEMECOIN_DISCOVERY_CANDIDATE_CAP` | 500 | the **unique** candidate set kept per run (after dedupe + prioritization) |
| enrichment cap | `MEMECOIN_MAX_ENRICH` | 120 | how many candidates receive a `/token-pairs/v1` call |
| final result limit | `?limit=` / `MEMECOIN_DEFAULT_LIMIT` | 20 (max 50) | how many **qualified** rows the response returns |

`?limit=20` never shrinks discovery or enrichment — every enriched, age-eligible
token still yields a stored snapshot regardless of `limit`.

### Prioritization before enrichment

When there are more candidates than the enrichment cap, they are ranked
deterministically. **Market cap is never used** (unknown before enrichment).
Descending "goodness":

1. number of discovery `sources`
2. boost signal present
3. profile freshness — position in `/token-profiles/latest/v1` (fresher first;
   "no profile" ranks last)
4. search occurrence count — how many `(term, pair)` search hits surfaced it
5. `token_key` ascending — a total, stable tie-break

### Chain diagnostics

Chains are **counted from the candidates actually seen** this run, never
hard-coded. `meta.diagnostics.chains_discovered` = `{ "<chain_id>": <unique
candidate count>, … }`, sorted descending. A chain that produced no candidates
simply does not appear — the system never claims a chain is "covered" just
because it is in a UI list.

### Coverage diagnostics

`meta.diagnostics` (and the persisted `ingestion_runs` columns) add:

```
trending_meta_enabled / trending_meta_count / trending_meta_slugs_used
trending_meta_pairs_seen / trending_meta_unique_candidates / trending_meta_tokens_unique
trending_meta_prefilter_dropped / trending_meta_prefilter_reasons
                              { market_cap_missing_or_zero, market_cap_above_ceiling,
                                liquidity_zero, volume_zero, pair_created_at_missing,
                                loose_age_exceeded }
trending_meta_ad_or_malformed_skipped
pre_filtered_candidates        (candidates surviving the pre-filter → dedup input)
deferred_candidates
keyword_discovery_enabled
search_term_budget / search_terms_used / search_terms_with_results / search_terms_empty
search_term_categories        { core, meta_slug, meta_name, ecosystem }
discovery_source_counts       { trending_meta, profile, boost, search }  (unique candidates per source)
chains_discovered             { <chain_id>: <count> }  (only chains actually observed)
raw_discovery_candidates / unique_candidates
discovery_candidate_cap / candidate_cap_dropped / candidates_considered
selected_for_enrichment / enrichment_deferred
not_qualified_peak_above_ceiling   (in-band floor cleared but peak > $200M)
```

### `GET /api/memecoins/discovery-status`

Read-only coverage report. **PostgreSQL (`ingestion_runs`) only — never calls
DexScreener**, never writes. Returns the latest run summary, the latest
*completed* run's discovery metrics, and its `chains_discovered` map:

```jsonc
{
  "data": {
    "latest_run":  { "id", "trigger", "status", "started_at", "completed_at" },
    "latest_completed_run": null,   // populated only when the latest run is not completed
    "discovery": {
      "raw_candidates", "unique_candidates", "selected_for_enrichment",
      "candidate_cap_dropped", "enriched_candidates", "age_eligible",
      "snapshots_written", "qualified", "search_terms_used", "search_terms_with_results"
    },
    "chains": { "solana": 210, "base": 80, "ethereum": 40 }
  },
  "meta": { "retrieved_at", "source": "ingestion_runs", "coverage_note" }
}
```

Only aggregate counts are persisted — **no raw candidate rows**.

### Rate-limit budget (unchanged cadence, no new concurrency)

Per run: ~4 calls to the 60/min bucket (profiles + 2 boost feeds + trending
metas) + ≤ 25 search calls + ≤ 120 enrichment calls (bounded batches of
`DEXSCREENER_ENRICH_CONCURRENCY` = 10) on the 300/min bucket. At the existing
10-minute cadence that is ~150 search calls/hour — well under 300/**min**.
Discovery frequency and enrichment concurrency are unchanged.

### Discovery coverage limitations

- Still a **sample**, not a census — `meta.coverage_note` says so.
- Ecosystem terms do **not** guarantee results on that chain (global search).
- Trending-meta terms depend on what DexScreener is surfacing that minute.
- Profile-freshness ranking assumes list order ≈ recency (DexScreener does not
  expose a profile timestamp).
- A token with no boost, no profile, an unguessable name, and no trending signal
  can still be missed entirely.

---

## Pipeline detail

1. **DISCOVER + dedupe** — build the search-term plan (see *Discovery Coverage
   Strategy*), collect hits from every source, every raw hit →
   `token_key = lower(chainId):lower(tokenAddress)`. Dedupe on `token_key` only
   (never pair address, never symbol). Optional `?chain=` filter applied here.
2. **Prioritise + cap** — deterministic ranking (source count → boost → profile
   freshness → search occurrence count → token key); trim to
   `MEMECOIN_DISCOVERY_CANDIDATE_CAP` (500), then enrich up to
   `MEMECOIN_MAX_ENRICH` (120). Both caps are independent of `?limit=` because
   every enriched, age-eligible token yields a stored snapshot.
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
trending_meta_enabled             is trending-meta discovery on (primary source)
trending_meta_count               metas expanded this run (≤ DEXSCREENER_TRENDING_META_LIMIT)
trending_meta_slugs_used          the meta slugs consumed
trending_meta_pairs_seen          member pair objects read across all metas
trending_meta_unique_candidates   distinct tokens contributed by trending meta (post pre-filter)
trending_meta_tokens_unique       distinct tokens seen in metas before pre-filter
trending_meta_prefilter_dropped   meta pairs rejected by the pre-filter
trending_meta_prefilter_reasons   { market_cap_missing_or_zero, market_cap_above_ceiling,
                                    liquidity_zero, volume_zero, pair_created_at_missing,
                                    loose_age_exceeded }
trending_meta_ad_or_malformed_skipped  meta rows with no chain/address/pair (incl. the paid ad)
pre_filtered_candidates           candidates surviving the pre-filter (dedup input)
deferred_candidates               unique candidates beyond MEMECOIN_MAX_ENRICH
keyword_discovery_enabled         is the keyword fallback on (default false)
search_terms_used / _with_results / _empty   search-term engine outcome (fallback only)
search_term_categories            { core, meta_slug, meta_name, ecosystem }
discovery_source_counts           { trending_meta, profile, boost, search } — unique candidates per source
chains_discovered                 { <chain_id>: <count> } — only chains actually observed, desc
discovery_candidate_cap / candidate_cap_dropped / candidates_considered
selected_for_enrichment / enrichment_deferred
age_unknown                       excluded: every pairCreatedAt null (not persisted)
older_than_max_age                excluded: age > 30d (not persisted)
age_eligible                      passed the age gate → persisted
market_cap_unknown                age-eligible tokens whose current snapshot MC is null
snapshots_written                 MarketSnapshot rows appended this run
persist_failed                    DB write failed for a candidate (logged, skipped)
new_tokens / existing_tokens      Token rows created vs already present
peak_updated                      observed_peak_market_cap raised this run
qualified                         age ≤ 30d AND a verified/observed market cap peak in [$5M, $200M]
                                  (CURRENT_OBSERVATION or HISTORICAL_VERIFIED only)
qualified_from_current_observation  qualified because THIS run's reading pushed the peak ≥ $5M
not_qualified                     age-eligible but no verified/observed market cap peak in band
observed_peak_below_threshold     subset of not_qualified where a peak value exists but is < $5M
not_qualified_peak_above_ceiling  subset of not_qualified where the verified/observed peak cleared
                                  $5M but exceeds $200M (outside the band — not re-qualified on current MC)
not_qualified_fdv_estimate_only   subset of not_qualified with an FDV estimate ≥ $5M but no
                                  verified/observed market cap (HISTORICAL_ESTIMATE — informational only)
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
MEMECOIN_SEARCH_TERMS=pepe,doge,cat,dog,frog,wif,inu,meme,shib,bonk,elon,ai,trump,politics,animal
MEMECOIN_ECOSYSTEM_TERMS=solana,base,ethereum,bsc,arbitrum   # supplementary search signals, NOT chain filters
MEMECOIN_SEARCH_TERM_BUDGET=25                               # total terms per run after merge + dedupe
DEXSCREENER_TRENDING_META_TERMS=8
MEMECOIN_DISCOVERY_CANDIDATE_CAP=500                          # unique candidates kept per run
MEMECOIN_MAX_ENRICH=120                                      # candidates enriched per run
MEMECOIN_OBSERVED_PEAK_MIN_USD=5000000
MEMECOIN_MAX_AGE_DAYS=30
MEMECOIN_DISCOVERY_INTERVAL_MINUTES=10   # scheduled ingestion cadence (1..60)

# Pump event detection (Step 16A) — heuristic MVP thresholds, not optimal
PUMP_MIN_MARKET_CAP_CHANGE_PCT=50
PUMP_MIN_PRICE_CHANGE_PCT=40
PUMP_MIN_VOLUME_CHANGE_RATIO=2.0         # rolling 24h ratio, NOT interval volume
PUMP_MIN_TRANSACTION_CHANGE_RATIO=2.0    # rolling 24h ratio, NOT interval txns
PUMP_MIN_CONFIRMATION_SIGNALS=2
PUMP_PRIMARY_WINDOW_MINUTES=60
PUMP_EVENT_MERGE_WINDOW_MINUTES=60
PUMP_EVENT_STALE_AFTER_MINUTES=90
# optional: MEMECOIN_DEFAULT_LIMIT, MEMECOIN_MAX_LIMIT, *_CACHE_TTL,
#           PUMP_ACCELERATION_WINDOW_MINUTES, PUMP_WINDOW_TOLERANCE_MINUTES,
#           PUMP_RECENT_SNAPSHOTS_PER_TOKEN, PUMP_MINIMUM_SNAPSHOTS, PUMP_SCORE_WEIGHT_*
```

---

## Limitations

- **Sample, not a census.** Coverage = the search-term engine (core meme terms +
  trending-meta terms + ecosystem terms, budgeted to 25) + DexScreener's paid
  activity feeds. `meta.coverage_note` states this. See *Discovery Coverage
  Strategy*.
- **Observed peak ≠ lifetime high.** See *Historical Observation Model* —
  accuracy grows as the scheduler keeps taking snapshots; it only matches the
  true peak for tokens watched since before that peak.
- **`pairCreatedAt` = pool creation, not token launch.** Stored as
  `earliest_pair_created_at`, never `token_created_at`.
- **`market_cap` depends on DexScreener knowing circulating supply.** When null
  we only have FDV — a different metric — and it never feeds `market_cap`.
- **Enrichment capped** at 120 tokens/run.
- **Pump detection is "observed", coarse, and heuristic.** It runs on ~10-minute
  snapshots — a pump-and-dump entirely between two observations is invisible;
  boundaries are snapshot timestamps, not tick data. `volume_h24` / `txns_h24`
  are *rolling 24h* metrics, so their change ratios are directional confirmation
  only, not interval growth. Thresholds are initial MVP guesses. A both-fields
  data glitch (MC and price spiking together on one bad reading) could produce a
  spurious `low`-confidence event. No "why" — catalysts are Step 16B/16C.
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
- **`Feature/DiscoveryCoverageTest`** (Step 14) — core search terms are used
  (lowercased) / trending-meta slugs + names are incorporated in priority order /
  duplicate terms de-duplicated / `MEMECOIN_SEARCH_TERM_BUDGET` respected /
  `MEMECOIN_DISCOVERY_CANDIDATE_CAP` respected (`candidate_cap_dropped` /
  `candidates_considered` / enrichment count) / a candidate's `sources` union
  across profile+boost+search / `discovery_source_counts` correct /
  `chains_discovered` reflects candidates actually seen (absent chain not
  claimed) / prioritization is deterministic and signal-ordered (source count →
  boost → profile freshness → search hits) / a repeated candidate → one token,
  one enrichment call / `?limit` does not shrink `unique_candidates` /
  `selected_for_enrichment` / `age_eligible` / empty search-term list handled /
  `search_terms_with_results` / `_empty` correct / `discovery-status` endpoint is
  read-only, never calls DexScreener, and handles "no runs".
- **`Feature/TrendingMetaDiscoveryTest`** (Step 19, 30 tests) — `/metas/trending/v1`
  is called and its slugs consumed / member pairs become candidates tagged
  `trending_meta` / one token in multiple metas is unioned (single candidate,
  `trending_metas` map) / the source set unions across trending meta + profile +
  boost + search / the paid narrative-bar ad (no chain/address/pair) is ignored /
  a meta pair with marketCap > $200M is pre-filtered out / marketCap ≤ $200M
  survives / **the $5M lower bound is NOT a pre-filter** (a $1M meta pair still
  enriched) / volume.h24 == 0 pre-filtered / liquidity.usd == 0 pre-filtered /
  missing marketCap pre-filtered / missing pairCreatedAt pre-filtered / loose
  pair age > 35 d pre-filtered / **final age validation uses
  `earliest_pair_created_at` from enrichment, not the meta pair's `pairCreatedAt`**
  / a peak in [$5M, $200M] qualifies / a current MC < $5M after an earlier in-band
  peak stays qualified / a `HISTORICAL_VERIFIED` peak in band qualifies with low
  current MC / a verified peak > $200M does NOT qualify even when current MC is
  back in band (`not_qualified_peak_above_ceiling` ≥ 1) / a `HISTORICAL_ESTIMATE`
  alone does not qualify / `observed_peak_market_cap` semantics unchanged /
  trending_meta outranks profile, boost and search in prioritization / more
  distinct trending metas ranks higher / candidate cap + enrichment cap respected
  / `DEXSCREENER_TRENDING_META_LIMIT` bounds how many metas are expanded / keyword
  search is OFF by default (no `/latest/dex/search` call) / keyword fallback works
  when `MEMECOIN_KEYWORD_DISCOVERY_ENABLED=true` / chain diagnostics reflect only
  chains actually seen / `discovery-status` exposes trending-meta coverage and
  never calls DexScreener / historical evidence stays separate, no external lookup
  for a `CURRENT_OBSERVATION` / trending meta can be disabled, leaving only the
  activity feeds.
- **`Feature/DiscoverySchedulerTest`** — `memecoins:discover` is scheduled
  `*/10 * * * *` / carries `--trigger=scheduled` / uses `withoutOverlapping()` /
  appears in `schedule:list`.
- **`Feature/PumpDetectionTest`** (Step 16A) — a confirmed pump (move + volume +
  txns) → one `high` event, `active` when the peak is the latest observation /
  a lone price move (one signal) → no event / too few snapshots → skipped / a
  market-cap move + one activity confirmation → `medium` event / a continuous
  pump across two runs stays one **merged** event (peak & start updated, id
  unchanged) / two separated pumps → two events, the first untouched / event
  `started_at` = trough, `peak_at` = highest market cap / a faded pump →
  `completed` with `ended_at` / `detection_score` is deterministic and grows
  with the move / market-cap + price only (no activity) → `low` / the volume
  ratio is stored as a ratio, nulls tolerated / a stale `active` event is swept
  to `completed` / **never calls DexScreener / any provider** / the command
  prints a summary and exits 0 / bounded query count / null `market_cap` falls
  back to price.
- **`Feature/PumpSchedulerTest`** — `memecoins:detect-pumps` is scheduled
  `5,15,25,35,45,55 * * * *` (same cadence as discovery, offset so it runs
  after ingestion) / uses `withoutOverlapping()` / appears in `schedule:list`.
- **`Feature/DockerComposeSchedulerTest`** — static checks on `docker-compose.yml`
  (no Docker daemon): exactly `{postgres, backend, scheduler, frontend}` /
  scheduler runs `schedule:work` / reuses the backend image / shares the
  `*backend-env` anchor with `DB_HOST: postgres` / bind-mounts the app source /
  `depends_on` postgres healthy / **no `ports:`** / `restart: unless-stopped`.
- **`Feature/MemecoinListTest`** — `GET /api/memecoins`: returns only qualified
  tokens / current MC < $5M with observed peak ≥ $5M still qualifies / a CoinGecko
  `HISTORICAL_VERIFIED` MC ≥ $5M qualifies even with low current MC / observed
  peak < $5M excluded / age > 30d excluded / **an FDV-estimate-only token is
  never returned** (and its estimate + observed peak stay intact) / an `UNKNOWN`
  token is never returned / **the main list only ever contains
  `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` with basis `current_market_cap` /
  `market_cap`, never `fdv_total_supply`** / **latest** snapshot supplies the
  current fields / `chain` filter / sort by observed peak DESC / `limit` works &
  is clamped / invalid params → `422` / DexScreener never called
  (`Http::assertNothingSent`) / empty DB → `{data: [], meta: {count: 0}}` /
  timestamps are ISO 8601 / **Step 20:** `qualification_crossed_at` /
  `qualification_crossing_type` / `recently_crossed` exposed (null when no
  crossing recorded) / default sort stays `peak_market_cap` /
  `?sort=recent_crossing` orders newest-crossing-first, no-crossing last /
  `?sort=peak_market_cap` still works / `?sort=bogus` → `422`.
- **`Feature/QualificationEventTest`** (Step 20) — first CURRENT_OBSERVATION
  ≥ $5M creates a crossing / previous snapshot < $5M → current ≥ $5M records at
  the current snapshot / the crossing uses the **earliest** ≥ $5M snapshot /
  repeated ≥ $5M runs do not duplicate (`(token_id, type)` unique) / the crossing
  survives a later dump below $5M / `HISTORICAL_VERIFIED` evidence creates a
  verified crossing at `first_verified_crossing_at` (falls back to
  `peak_observed_at`) / `HISTORICAL_ESTIMATE` creates **no** verified crossing /
  `UNKNOWN` / no-evidence create nothing / a verified/observed peak > $200M
  creates nothing / `HISTORICAL_VERIFIED` + `CURRENT_OBSERVATION` rows coexist and
  the verified one is representative (original CO row preserved) / identity is
  chain + address / **the discovery pipeline records the crossing and a second
  run is idempotent** / the detail endpoint exposes `qualification_timeline`.
- **`Feature/RecentlyCrossedTest`** (Step 20) — `GET /api/memecoins/recently-crossed`
  returns tokens crossed within the default 48h window / is read-only and makes
  **no** provider calls (`Http::assertNothingSent`, ≤ 6 queries) / `?hours=`
  widens the window / `?hours=` is capped at 168 (`422` above / at 0) / a token
  **below $5M now** still appears if it recently crossed (`COOLED`) / a token
  ≥ $5M shows `ACTIVE` / an estimate-only token never appears / an age > 30d
  token is excluded (record kept) / the **representative** crossing drives window
  membership / newest crossing first.
- **`Feature/MemecoinDetailTest`** (Step 15 nested shape) —
  `GET /api/memecoins/{chainId}/{tokenAddress}`: returns the token / identified by
  chain + address (same address, two chains → two tokens) / a symbol is **not** a
  valid identity → `404` / **latest** snapshot supplies `data.latest.*` &
  `data.snapshots` is newest-first / snapshots capped at 50 while all rows stay
  stored / **historical qualification evidence returned** (`data.qualification.*`
  from the stored row) / an **FDV estimate is exposed only in the separate
  `data.historical_estimate` block** (`estimated_fdv_usd` / `estimate_source` /
  `estimate_basis` / `estimate_confidence` / disclaimer note) — `qualification`
  reports `qualified: false`, `peak_value: null`, `basis: null`, and the payload
  contains **no `historical_market_cap` key** / `CURRENT_OBSERVATION` derived when
  no evidence row exists / an unqualified token reports `UNKNOWN` (not a denial) /
  **`qualification.peak_value` stays distinct from `observed.peak_market_cap`**
  and the read never writes either / missing token →
  `404 {"error": "Memecoin not found."}` / endpoint is read-only (token, snapshot
  **and evidence** counts unchanged) / DexScreener / CoinGecko / GeckoTerminal
  never called / a token below $5M **and** older than 30 days still resolves /
  null fields stay `null` / the live chart pair address is returned & never the
  token address / no per-snapshot N+1 (≤ 10 queries) / `data.token_narrative`
  is `pending` with no report, exposes a completed report, and never leaks
  provider error detail.
- **`Feature/HistoricalQualificationTest`** (Step 13C, CoinGecko + GeckoTerminal
  fully HTTP-faked) — current MC ≥ $5M → `CURRENT_OBSERVATION` immediately with
  no provider call / current MC < $5M triggers a lookup only when eligible /
  CoinGecko non-zero market cap ≥ $5M → `HISTORICAL_VERIFIED` / CoinGecko 404 →
  GeckoTerminal fallback / CoinGecko all-zero `market_caps` → not verified /
  GeckoTerminal safe immutable supply → `HISTORICAL_ESTIMATE` (`fdv_total_supply`,
  medium confidence) — **and it mirrors to `historical_estimate_fdv_usd`, never
  `historical_peak_value`, and `qualifies()` is false** / a `HISTORICAL_VERIFIED`
  market cap mirrors to `historical_peak_value`, never the estimate column /
  mutable mint authority → `UNKNOWN` / missing supply → `UNKNOWN` / no mint signal
  → `UNKNOWN` unless opted in (then low confidence) / `UNKNOWN` never qualifies /
  evidence never overwrites `observed_peak_market_cap` / existing observed peak
  ≥ $5M stays authoritative `CURRENT_OBSERVATION` /
  age > 30 d never qualifies even with a verified $50M peak / 6 h lookup cooldown
  blocks repeat provider calls / cooldown expiry re-evaluates an `UNKNOWN` into
  `HISTORICAL_VERIFIED` / evidence row persisted / a sub-threshold estimate never
  leaks an FDV value as `market_cap` / source + basis preserved / two chains stay
  distinct / provider 429 → safe `UNKNOWN` / read API exposes `qualification_*`
  fields / read API derives `CURRENT_OBSERVATION` with no evidence row /
  per-run lookup budget bounds external calls / cold-start end-to-end: an
  FDV-estimate-only crashed token is **stored** (evidence + `historical_estimate_fdv_usd`)
  with `observed_peak_market_cap` unchanged at $1M, but is **NOT** in the
  discovery result and **NOT** in `GET /api/memecoins` (`not_qualified_fdv_estimate_only`
  diagnostic ≥ 1).
- **`Feature/NarrativeResearchTest`** (Step 21, Anthropic HTTP-faked) — origin +
  popularity reports persisted / `token_narrative_sources` persisted with
  metadata / `published_at` preserved or null (never fabricated) / factual
  statements carry the real persisted source ids / a fact with no source id →
  section `failed` / a cited un-supplied id → section `failed` / malformed
  output rejected / a fabricated **creator-intent** claim rejected / **causal**
  popularity language rejected / evidence that looks like an instruction is sent
  as data, not in the system prompt / the timeline is sorted chronologically /
  the ranker keeps one strong primary source above 20 anonymous reposts / token
  identity is chain + address / cooldown skips re-research / `--force` ignores
  it / a **partial** result keeps the completed section / an AI-provider failure
  does **not** destroy an existing good report / zero sources → honest UNKNOWN,
  not a fabricated story / the detail API never triggers research / `pending`
  when no report / a completed report is exposed / a failed section leaks no
  provider error detail / existing pump / evidence / qualification / observed
  peak are untouched / the GDELT provider degrades to no sources when
  unavailable (run never fails).
- **`Feature/NarrativeSchedulerTest`** (Step 21) — `memecoins:research-narratives`
  is scheduled **hourly** (`0 * * * *`), `withoutOverlapping(30)`, NOT on the
  discovery cadence, and appears in `schedule:list`.
