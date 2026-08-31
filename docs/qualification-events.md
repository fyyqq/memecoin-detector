# Qualification Events — "Recently Crossed $5M" (Step 20)

Makes the detector answer a time-based question instead of only showing a static
ranking:

> **Which tracked memecoins have most recently crossed the $5M market-cap
> threshold?**

Nothing about the qualification universe changes — `$5M` floor, `$200M` ceiling,
age ≤ 30 days, `volume > 0`, `liquidity > 0`, and the "`HISTORICAL_ESTIMATE`
never qualifies" rule are all untouched. Step 20 only **records when the crossing
happened** and surfaces it.

---

## The crossing event

For every age-eligible token the pipeline determines the earliest observation at
which a **verified / observed** market cap cleared `$5M`:

| `type` | Condition | `crossed_at` | `source` |
|---|---|---|---|
| `CURRENT_OBSERVATION` | one of OUR `market_snapshots` has `market_cap ≥ $5M` | timestamp of the **earliest** such snapshot | `dexscreener` |
| `HISTORICAL_VERIFIED` | CoinGecko returned a verified historical market-cap point `≥ $5M` | timestamp of the **earliest** verified `≥ $5M` point in the series (`historical_peak_evidences.first_verified_crossing_at`, falling back to `peak_observed_at`) | `coingecko` |

**`HISTORICAL_ESTIMATE` (FDV basis) produces no crossing event.** An estimated
FDV is not a verified market cap, and calling it a crossing would be dishonest.
It stays visible on the detail page as *"Estimated historical FDV ≥ $5M"*, never
in "Recently Crossed $5M". `UNKNOWN` produces nothing either — it is **not** a
claim that the token never reached `$5M`.

### Current-observation crossing

```
previous snapshot  market_cap < $5M
current  snapshot  market_cap ≥ $5M   →  crossing recorded at the current snapshot's observed_at
```

The very first snapshot we ever take of a token that is already `≥ $5M` also
counts — `crossed_at` is that snapshot's timestamp. We never claim it is the
tick-level moment the market crossed; it is *the earliest point OUR ~10-minute
observations saw it above `$5M`*.

### Historically-verified crossing

CoinGecko `market_chart` data is candled / sampled. `crossed_at` is the earliest
sampled point at or above `$5M` — labelled **"historically verified crossing"**,
never presented as an exact tick.

---

## Precedence — verified beats observed

A token can hold **both** a `CURRENT_OBSERVATION` and a `HISTORICAL_VERIFIED`
row (unique on `(token_id, type)`). The **representative** crossing — the one the
APIs report and sort on — is the strongest:

```
HISTORICAL_VERIFIED  >  CURRENT_OBSERVATION
```

The other row is **preserved untouched** for the record. So if we first observe
a crossing today, then CoinGecko later verifies the token actually crossed ten
days ago, the representative crossing becomes the (earlier, more accurate)
verified one — and the token correctly drops out of a 48-hour "recently crossed"
window. The original current-observation record still shows in the detail-page
timeline.

---

## Data model

### `qualification_events`

| Column | Meaning |
|---|---|
| `token_id` | FK. `(token_id, type)` is **unique** → idempotent scheduler re-runs. |
| `type` | `CURRENT_OBSERVATION` \| `HISTORICAL_VERIFIED` |
| `crossed_at` | the crossing timestamp (see above) — **never rewritten once set** |
| `threshold_usd` | the threshold in force when recorded (usually `5000000`) |
| `evidence_status` | the `HistoricalPeakEvidence` status at creation (mirrors `type`; explicit provenance) |
| `source` | `dexscreener` \| `coingecko` |
| `market_cap_value` | market cap at the crossing point (nullable) |
| `created_at` / `updated_at` | |

`historical_peak_evidences` gains one nullable column,
`first_verified_crossing_at` — the earliest CoinGecko `≥ $5M` point (distinct
from `peak_observed_at`, which is the point of the *maximum* historical market
cap).

### `QualificationEvent` model

`belongsTo Token`; `Token hasMany qualificationEvents`.
`Token::representativeQualificationEvent()` returns the strongest eager-loaded
row (no query).

---

## Where events are created

Inside the existing discovery / qualification pipeline, right after the
historical-qualification step:

```
… → AGE FILTER → PERSIST TOKEN + SNAPSHOT
   → CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP → QUALIFICATION ($5M–$200M)
   → RECORD QUALIFICATION EVENTS   ← Step 20
   → PERSIST EVIDENCE
```

`App\Services\Historical\QualificationEventRecorder::recordBatch()`:

1. keeps only entries whose evidence `qualifies($5M, $200M)` — same "qualified"
   definition the main list uses (a verified/observed peak **above `$200M`** gets
   no event; age is already enforced upstream);
2. one query loads every existing event for the batch (`token_id → type`);
3. for each token missing its matching-type row, creates it (one indexed
   snapshot query for a `CURRENT_OBSERVATION` `crossed_at`);
4. returns `{qualification_events_created, qualification_events_existing}` for the
   run diagnostics.

**Read APIs never create an event and never scan snapshots.**

### Idempotency

```
run 1:  crossing event created
run 2:  token still ≥ $5M  →  same event, nothing new  (qualification_events_existing++)
```

The `(token_id, type)` unique index + the pre-load make repeated scheduler runs
free. `crossed_at` is set once and never rewritten.

---

## Active vs Cooled

A neutral, non-alarmist status on the "Recently Crossed" list, derived from the
**latest** snapshot:

| Status | Rule |
|---|---|
| `ACTIVE` | current market cap `≥ $5M` |
| `COOLED` | current market cap `< $5M` |

`COOLED` is not a failure — the floor is a **peak rule**. A token that crossed
`$5M`, then dumped to `$2M`, is still qualified and still appears in "Recently
Crossed $5M" for the length of the window.

---

## The 48-hour window

`recently_crossed` = representative `crossed_at ≥ now − MEMECOIN_RECENT_CROSSING_HOURS`
(default **48**, `config('dexscreener.recent_crossing.hours')`).

`GET /api/memecoins/recently-crossed?hours=` overrides it, capped at
`MEMECOIN_RECENT_CROSSING_MAX_HOURS` (default **168** = 7 days).

---

## Age rule (unchanged)

Only tokens with `age ≤ 30 days` appear in the **active** dashboard lists
(`GET /api/memecoins`, `GET /api/memecoins/recently-crossed`). A token can cross
`$5M` and later age past 30 days — once it does, it drops out of the active
lists, **but its `qualification_events` row is kept**. The historical record is
never deleted.

---

## API

### `GET /api/memecoins` — new fields + sort

```jsonc
{
  "qualification_status": "CURRENT_OBSERVATION",
  "qualification_crossed_at": "2026-08-31T08:30:00+00:00",   // representative crossing, or null
  "qualification_crossing_type": "CURRENT_OBSERVATION",       // or "HISTORICAL_VERIFIED" / null
  "recently_crossed": true
}
```

`meta.sort` (`peak_market_cap` | `recent_crossing`) and
`meta.recent_crossing_hours` are echoed.

`?sort=recent_crossing` orders by the representative `crossed_at` DESC, tokens
with no recorded crossing last. **The default stays `peak_market_cap`** — the
"Recently Crossed $5M" section already serves the recency view, and re-ordering
the main leaderboard on every crossing would disorient regular dashboard users.

### `GET /api/memecoins/recently-crossed`

Read-only. **PostgreSQL only — never DexScreener / CoinGecko / GeckoTerminal**,
never writes, never creates an event.

- default window 48h; `?hours=` (1 … 168); optional `?chain=`.
- returns currently-**qualified** tokens (age ≤ 30d, verified/observed peak in
  `[$5M, $200M]`) whose **representative** crossing is inside the window.
- a token with current MC **below `$5M`** still appears — it previously crossed.
- newest crossing first.

```jsonc
{
  "data": [
    {
      "symbol": "MEME", "chain_id": "solana",
      "current_market_cap": 4200000,
      "observed_peak_market_cap": 7200000,
      "qualification_peak": 7200000,
      "crossed_at": "2026-08-31T10:12:00+00:00",
      "crossing_type": "CURRENT_OBSERVATION",
      "crossing_market_cap_value": 5100000,
      "status": "COOLED",
      "age_days": 2.1
    }
  ],
  "meta": { "hours": 48, "count": 1, "source": "postgresql" }
}
```

### `GET /api/memecoins/{chainId}/{tokenAddress}` — `qualification_timeline`

```jsonc
"qualification_timeline": {
  "crossed_at": "2026-08-31T08:30:00+00:00",
  "crossing_type": "CURRENT_OBSERVATION",
  "crossing_source": "dexscreener",
  "crossing_market_cap_value": 5100000,
  "recently_crossed": true,
  "currently_below_threshold": true,      // latest MC < $5M (still qualified)
  "threshold_usd": 5000000,
  "events": [
    { "type": "CURRENT_OBSERVATION", "crossed_at": "…", "source": "dexscreener", "market_cap_value": 5100000 }
  ]
}
```

---

## Frontend

- **Dashboard** splits into two sections: **🔥 Recently Crossed $5M** (compact
  card list — Token / Chain / Crossed / Current MC / Peak MC / `ACTIVE`|`COOLED`)
  above the existing **30-Day Qualified Memecoins** table. The table gains a
  Sort control (Peak market cap / Recent crossing). All existing dashboard
  behaviour is retained.
- **Detail page** gains a **Qualification timeline** section: *Crossed $5M*,
  *Crossing type* ("Current observation" / "Historically verified crossing"),
  MC at crossing, Current MC, Peak MC. When the current MC is below `$5M` it
  says *"Current MC is below $5M, but the token remains qualified because it
  previously crossed the threshold."* — it never says the token is currently
  above the threshold.
- The qualification **badge** (`CURRENT` / `VERIFIED` / `ESTIMATE` / `UNKNOWN`)
  is unchanged.

---

## Limitations

- **Lazy backfill.** A `qualification_events` row is created the next time a
  token is observed by the pipeline *while still age-eligible*. Tokens that were
  already qualified before Step 20, or that have fallen out of every discovery
  feed, get no event until they are rediscovered. The scheduler rediscovers
  active tokens every 10 minutes, so anything currently relevant gets an event
  within one cycle; a token that has completely stopped trending may show
  `qualification_crossed_at: null` until it reappears.
- **Observation-series resolution.** A `CURRENT_OBSERVATION` `crossed_at` is a
  snapshot timestamp (~10-minute cadence), not the tick-level crossing.
- **Candled external data.** A `HISTORICAL_VERIFIED` `crossed_at` is the earliest
  *sampled* CoinGecko point `≥ $5M`, labelled as such.
- **Cold start.** For a token first observed today we cannot know it crossed
  `$5M` before today; the `CURRENT_OBSERVATION` crossing is dated from our first
  qualifying snapshot, which can post-date the real market crossing. CoinGecko
  verification (when available) recovers the true earlier date.
- **No estimate-only crossings.** By design there is no "estimated crossing"
  concept — it would be a separately-labelled future feature, never mixed into
  "Recently Crossed $5M".
