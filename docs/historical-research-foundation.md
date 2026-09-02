# Historical Research Foundation (Step 26 — Phase 1)

The typed foundation for **evidence-based historical reconstruction** of past
"Monthly Top Memecoins" buckets. Phase 1 adds the capability model, the typed
metric result, the deterministic confidence model, and the
`monthly_ranking_evidence` child table — **no** ranking behaviour changes, **no**
providers, **no** command, **no** frontend.

See [monthly-rankings.md](monthly-rankings.md) for the Step 25 ranking it feeds,
and the Step 26A audit for why this is needed.

---

## 1. Why evidence is a CHILD table

Step 25 stored historical provenance in one loose `monthly_rankings.source_evidence`
JSON blob — a flat list of `{name, url, claim}` with **no per-metric
attribution**. You could not answer "where did the holder count come from vs the
volume?".

`monthly_ranking_evidence` is a **child table of `monthly_rankings`** (one row
per metric per source per ranked entry). It is **not another ranking table** — it
never selects or orders anything. It exists so a bucket's Top 3 can be explained
**source-by-source**:

> `#1 ANSEM` — holder count **45,000** (Etherscan snapshot 2026-01-31, *observed*,
> high) · monthly volume **$180M** (CoinGecko `total_volumes[]` sum, *reconstructed*,
> medium) · market cap **$55M** (CoinGecko `market_caps[]` peak, *observed*, high)

`monthly_rankings.source_evidence` stays for backward compatibility; structured
evidence now lives in the child table.

`MonthlyRanking::evidence()` is a `hasMany` relation — **read-only from the API
layer**, written only by the historical research pipeline (later phases).

---

## 2. Metric-level provenance

`App\Services\Historical\Research\HistoricalMetric` — the metrics a provider can
be asked for:

| Metric | Stored as | Feeds the score? |
|---|---|---|
| `holders` | `value_numeric` | yes (weight 0.40) |
| `volume` | `value_numeric` | yes (weight 0.35) |
| `market_cap` | `value_numeric` | yes (weight 0.25) — OBSERVED / VERIFIED circulating MC only, never FDV |
| `ohlcv` | `metadata` (candle summary) | no — supporting context |
| `identity` | `metadata` (`chain_id` / `token_address` / name / symbol) | no |
| `pool_date` | `observed_at` | no — the ≤ 30-day trading-age check |

Every fetched figure is a `HistoricalMetricResult` carrying: `metric`,
`available`, `value`, `source_name`, `source_url`, `observed_at`, `methodology`,
`basis`, `confidence`, `limitations`, `metadata`.

An **unavailable** metric is a first-class value: `available = false`,
`value = null`, `basis = none`, `confidence = unknown`. It **never** pushes a
fabricated number into scoring — callers check `available` / `scalarValue()`, and
a missing metric is an **absent evidence row** (the participation score
renormalizes over the components that are known, exactly as in Step 25).

---

## 3. `basis` — observed vs reconstructed vs estimate

`App\Services\Historical\Research\MetricBasis`:

| Basis | Meaning | Example |
|---|---|---|
| `observed` | the source directly reports this figure for the month | a CoinGecko `market_caps[]` point; a dated explorer snapshot of `holders.count` |
| `reconstructed` | aggregated / derived from lower-level data that **is** directly observed | summing daily `total_volumes[]` into a monthly volume |
| `estimate` | a modelled figure that depends on an assumption | price × supply — **never** usable as a verified market cap |
| `none` | the metric is unavailable; there is no value | — |

There is **deliberately no "verified" basis**. A metric is only ever `observed`,
`reconstructed`, `estimate`, or `none`. An `estimate` is **always** labelled an
estimate and is **hard-capped at `low` confidence** no matter how strong the
source.

---

## 4. Confidence bands (deterministic)

`App\Services\Historical\Research\HistoricalConfidence` — `high` / `medium` /
`low` / `unknown`. It is **evidence quality**, NOT a probability of anything.

`HistoricalConfidenceCalculator::evaluate(HistoricalConfidenceSignals)` derives
it deterministically — it is never a hand-typed number:

1. metric not available → **`unknown`** (stop).
2. base band from source credibility
   (`App\Services\Historical\Research\SourceCredibility`, string values shared
   with `App\Services\Ranking\MonthlyResearchSource`):
   - `primary_market_data` / `historical_provider` / `archived_dexscreener` → `high`
   - `reputable_reporting` → `medium`
   - `secondary` → `low`
   - `low_quality` → `unknown`
3. −1 band if there is **no `observed_at` timestamp**.
4. −1 band if **token identity is not verified** (`chain_id` + `token_address`).
5. basis: `reconstructed` → −1 band; `estimate` → hard cap at `low`.
6. +1 band if **≥ 1 corroborating source** AND basis is `observed` (never above
   `high`). *(Full cross-source reconciliation is a later phase; the calculator
   already accepts the count.)*

Band then clamped to `[unknown, high]`.

---

## 5. `monthly_ranking_evidence` schema

| Column | Notes |
|---|---|
| `id` | |
| `monthly_ranking_id` | FK → `monthly_rankings`, `cascadeOnDelete` |
| `metric` | `holders` / `volume` / `market_cap` / `ohlcv` / `identity` / `pool_date` |
| `source_name` | e.g. `CoinGecko`, `GeckoTerminal`, `Etherscan`, `operator seed` |
| `source_url` | nullable |
| `value_numeric` | the scalar figure for a scoring metric; **null** for `identity` / `ohlcv` / `pool_date` and for an unavailable metric — a missing metric is an **absent row, not a 0** |
| `observed_at` | the source's data timestamp |
| `methodology` | short prose — how the figure was obtained |
| `basis` | `observed` / `reconstructed` / `estimate` |
| `confidence` | `high` / `medium` / `low` / `unknown` — deterministic |
| `limitations` | known caveats (partial month coverage, un-listed token, …) |
| `metadata` (json) | structured detail for non-scalar metrics — never a scraped page body |
| `dedupe_hash` | `sha256(metric + normalized source_name + source URL host)` |
| `created_at` / `updated_at` | |

**Uniqueness:** `unique(monthly_ranking_id, dedupe_hash)` → the same figure from
the same source for the same ranked entry upserts **one** row. Re-running
research is idempotent. Index on `(monthly_ranking_id, metric)`.

**Fake values are never stored.** Not every metric must exist for a row.

---

## 6. The holder-history gap (still unsolved by automation)

The Step 26A audit confirmed: **no free public API exposes a historical holder
count time series.** CoinGecko and GeckoTerminal `/info` both return the
**current** count only.

- `holders` is the **highest-weighted** score input (0.40).
- For any past month, automated providers will return
  `HistoricalMetricResult::unavailable(HistoricalMetric::Holders)`.
- The score then renormalizes over `volume` + `market_cap` — honest, but it
  means backfilled rankings are structurally volume + MC-driven unless an
  **operator** supplies a dated holder figure (explorer snapshot, archived page)
  through the seed file.

This is a **known limitation**, surfaced in the UI and docs — never papered over
with a current count.

---

## 7. Robinhood — seed / operator only

`config/historical.php` `chain_map` has **no `robinhood` entry**, and no
verified GeckoTerminal / CoinGecko network id for it. Until a real provider
network mapping is confirmed, Robinhood buckets are **operator-seed only** for
every metric. No mapping is added on assumption.

---

## 8. `web_research` stays OFF

There is no free official API for a historical monthly DexScreener leaderboard,
and search-engine result pages are not scraped. `web_research`
(`ranking.research.web.enabled`) remains **OFF** and returns `[]`.

---

## 9. What Phase 1 does NOT do

- does **not** touch `MonthlyPerformanceCalculator`, the 40/35/25 weights, or
  Top-3 selection;
- does **not** touch historical qualification (`HistoricalPeakEvidence`) or risk
  screening;
- does **not** extract CoinGecko / GeckoTerminal volume yet;
- does **not** implement cross-source reconciliation;
- does **not** add the `memecoins:historical-monthly-research` command;
- does **not** add a scheduler entry;
- does **not** change the frontend.

Those are later phases.
