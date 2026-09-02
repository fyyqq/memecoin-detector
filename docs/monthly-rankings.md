# Monthly Top Memecoins (Step 25 — Top 3, participation score)

For **every calendar month**, the **Top 3** performing memecoins inside each of
the **five fixed chain buckets** — `solana` / `robinhood` / `bsc` / `base` /
`other` (every non-core chain). `monthly_rankings` is **unique on
`(year, month, chain_bucket, rank)`** with `rank ∈ {1, 2, 3}` → at most
`12 × 5 × 3 = 180` rows a year. There is **no global monthly winner**.

The token keeps its real `chain_id`; only `monthly_rankings.chain_bucket` ever
says `"other"` (`ChainBucket::forChain()`). Risk score, AI and social sentiment
are **never** used. The 0–100 score is **not** a prediction of returns.

---

## 1. The participation score

The ranking rewards real **participation**, not size or percentage growth:

```
strength(x, ref) = min(1, ln(1 + x) / ln(1 + ref))          # deterministic capped-log

holder_strength      = strength(holder_count,       ranking.references.holder_count   [10 000])
volume_strength      = strength(monthly_volume_usd, ranking.references.volume_usd     [$20M])
market_cap_strength  = strength(month_peak_mc,      ranking.references.market_cap_usd [$50M])

# renormalize over the components that are actually KNOWN
score = 100 · Σ(weight · strength) / Σ(weight)
```

Default weights (`config/ranking.php`, env-configurable):
`holder 0.40 · volume 0.35 · market_cap 0.25`.

- **`holder_count`** — a monthly **maximum / representative** observation.
  Internal (provisional month): the max GeckoTerminal `/info` `holders.count`
  seen during the month (the monthly **holder pass**, §3). Researched: the
  operator `holder_count` from the seed row. **`null` means UNKNOWN** — it is
  dropped from the sum and the remaining weights renormalize (it is **never**
  treated as 0, and a **current** count is **never** used to represent a past
  month).
- **`monthly_volume_usd`** — internal: the **median in-month `volume_h24`** over
  the token's eligible snapshots (a representative daily figure; summing
  rolling-24h samples would double-count). Researched: the operator `volume_usd`
  (monthly). `0` ⇒ the token is **ineligible**.
- **`month_market_cap`** — the highest **OBSERVED / VERIFIED** market cap in the
  month. Never current, never FDV, never `HISTORICAL_ESTIMATE`.

**Market cap cannot dominate.** A $150M token does **not** automatically beat a
$20M token with far stronger holders + volume. A **researched** candidate scored
on market cap *alone* (no holder count, no volume) has its score multiplied by
`ranking.market_cap_only_penalty` (0.5) — it is recorded but can never outrank
real participation.

**`market_cap_growth_pct` / `peak_expansion_ratio` / `activity_score`** are still
computed and shown in the API `performance` block + `scoring_breakdown.context`,
but they are **info-only** — never part of the score or the ordering.

### Tie-break (deterministic)

1. higher `holder_strength`
2. higher `volume_strength`
3. higher `market_cap_strength`
4. higher `observation_coverage_ratio`
5. token key (`chain_id:token_address`) ascending

A token appears **at most once** per bucket.

---

## 2. Eligible universe (unchanged from Step 19)

- age ≤ 30 days per in-month snapshot (`earliest_pair_created_at`, real pool age);
- a **VERIFIED / OBSERVED** market-cap peak in `[$5M, $200M]`
  (`CURRENT_OBSERVATION` or CoinGecko `HISTORICAL_VERIFIED`) — **`HISTORICAL_ESTIMATE`
  and `UNKNOWN` never qualify**, and `UNKNOWN` is never coerced to a number;
- no in-month snapshot ever above $200M;
- month-peak MC ≥ $5M · volume > 0 · liquidity > 0;
- belongs to the bucket.

---

## 3. The monthly holder pass

The **current provisional month** polls **GeckoTerminal `/info`** (reusing
`App\Services\Risk\GeckoTerminalInfoClient` — the same adapter the risk screen
uses; free, no key, never throws) for the eligible candidate tokens, **once a
day inside `memecoins:finalize-monthly-champion`**.

- there is **no `market_snapshots` schema change** and **no holder capture in the
  10-minute discovery loop**;
- a per-run token cap (`ranking.holder_pass.max_tokens_per_run` = 25) and a
  per-token cooldown (`ranking.holder_pass.cooldown_hours` = 20, read from the
  prior `monthly_rankings.holder_checked_at`) keep the provider load tiny;
- the stored count is `max(prior stored, fresh)` — the **monthly maximum**,
  carried forward across daily runs on the ranking rows themselves;
- GeckoTerminal returning nothing → `holder_count` stays `null` (UNKNOWN);
- a **completed past month never runs the pass** (no live holder history) — a
  finalized past bucket gets a holder count **only** from an operator seed row.

Limitation: a token that drops out of the Top 3 for a day can lose its prior
carried-forward max — acceptable for a *provisional* figure.

---

## 4. Per-bucket status

| Status | Meaning |
|---|---|
| `provisional` | the current month; entries recomputed daily and may change |
| `finalized` | a completed month with defensible ranked entries. An entry may carry `confidence: low` where the evidence is thin (thin observation coverage, a single source, `age_uncertain`, or a market-cap-only researched candidate). |
| `no_verified_result` | a completed month with **no** defensible candidate — a single rank-1 row, `token_id` null, `entries: []` in the API. **Never a fabricated position.** |
| `future` | a month that has not happened yet — no stored row, `entries: []`. |

Confidence: `high` (coverage ≥ 0.5 internal, or ≥ 2 strong sources + full
figures + known age researched) / `medium` / `low`. The operator's suggested
confidence is a **ceiling** the service may lower, never raise.

---

## 5. Commands

### `memecoins:finalize-monthly-champion` — daily, deterministic, internal only

No-arg run: refresh the current month's 5 buckets (`provisional`, incl. the
holder pass) + settle every not-yet-settled bucket of the previous completed
month. `--year= --month= [--chain=] [--force]` settle one month. Refuses an
incomplete month without `--force`; a settled past bucket is immutable without
`--force`. **Never** calls a provider except the GeckoTerminal holder pass for
the current month. Scheduled daily `00:20`, `withoutOverlapping`.

### `memecoins:research-monthly-champions` — on-demand historical backfill

`--year= --month= [--chain=solana|robinhood|bsc|base|other] [--force]`. **Not
scheduled.** For a completed past month it gathers candidates from the ordered
providers (`ranking.research.providers` = `internal_observed, seed_file`;
`web_research` is an OFF-by-default stub), resolves identity (name + symbol +
chain, ideally an address — never symbol alone; the declared bucket **and** the
real chain must both map to the bucket), re-validates `$5M–$200M` **market cap**
(never FDV) / bucket / month / ≤ 30-day trading age (`age_uncertain` + capped
confidence when the launch date is unknown), re-scores with the **same
participation formula**, ranks a **Top 3**, and classifies each row `finalized`
or `no_verified_result`.

**It never fabricates** a candidate / URL / date / holder count, **never**
claims an exact DexScreener rank without a source that establishes it (→
`source_type: best_supported_historical_performer`), **never** scrapes SERPs,
**never** reads the Risk Assessment, **never** uses AI.

#### The seed file — the manual-research bridge

`ranking.research.seed_path` (default
`storage/app/monthly-champion-candidates.json`, **gitignored**). An operator
records verified historical candidates — **multiple per `(year, month, bucket)`**,
ranked into a Top 3 by the score:

```jsonc
{ "candidates": [ {
  "year": 2026, "month": 1, "chain_bucket": "solana",
  "name": "...", "symbol": "...", "chain_id": "solana", "token_address": "...",
  "baseline_market_cap": 6000000, "peak_market_cap": 45000000,
  "volume_usd": 12000000,
  "holder_count": 18000,            // real positive integer ONLY — omit / null = UNKNOWN
  "launch_date": "2025-12-20", "age_uncertain": false,
  "source_type": "best_supported_historical_performer",   // or exact_dexscreener_rank
  "confidence": "medium",
  "sources": [ { "name": "CoinGecko", "url": "https://…", "claim": "…",
                 "published_at": "2026-02-01", "credibility": "historical_provider" } ],
  "explanation": "why this is a top-3 performer"
} ] }
```

Absent file / bad JSON / nothing for the bucket → `no_verified_result`, honestly.

---

## 6. Database — `monthly_rankings`

`unique(year, month, chain_bucket, rank)`. Columns of note:

| Column | Meaning |
|---|---|
| `rank` | 1..3 |
| `token_id` | tracked `Token`, or null for a denormalized researched champion (`champion_name` / `champion_symbol` / `champion_chain_id` / `champion_token_address` / `champion_image_url`) or a `no_verified_result` row. `tokens.chain_id` is never mutated. |
| `status` | `provisional` / `finalized` / `no_verified_result` / `future` |
| `performance_score` | 0–100 participation score (null for `no_verified_result` / an unscorable researched candidate) |
| `holder_count` | monthly max / representative — **null = UNKNOWN** |
| `monthly_volume_usd` | representative monthly volume |
| `month_market_cap` | month-peak observed/verified MC (the MC-strength basis) |
| `holder_strength` / `volume_strength` / `market_cap_strength` | the normalized `[0,1]` components — the transparent audit trail |
| `holder_checked_at` | last GeckoTerminal poll (the holder-pass cooldown key) |
| `baseline_market_cap` / `market_cap_growth_pct` / `peak_expansion_ratio` / `activity_score` | **info-only** context |
| `observation_count` / `observation_coverage_ratio` | internal-observed only |
| `source_type` / `source_reference` / `source_evidence` (`[{name,url,claim,published_at,credibility}]`) / `age_uncertain` / `confidence` | provenance |
| `scoring_breakdown` (json) | weights used, strengths, candidate counts, runner-up score, method |
| `finalized_at` / `computed_at` | |

Written **only** by `MonthlyChampionService` (finalize) and
`MonthlyChampionResearchService` (research). The GET API never recomputes.

---

## 7. API — `GET /api/memecoins/monthly-champions?year=YYYY`

Read-only, `monthly_rankings` only — never recomputes, never queries
`market_snapshots`, never calls a provider, never researches. Always **12 months
× 5 buckets**. Each bucket:

```jsonc
"solana": {
  "chain_bucket": "solana",
  "status": "finalized",                 // provisional | finalized | future | no_verified_result
  "entries": [                            // 0..3, rank order — [] for future / no_verified_result
    {
      "rank": 1,
      "token": { "id": 42, "symbol": "ANSEM", "name": "…", "chain_id": "solana",
                 "chain_bucket": "solana", "token_address": "…", "image_url": null },
      "performance": {
        "score": 92.4,
        "holder_count": 45000,            // null => UNKNOWN
        "monthly_volume": 180000000,
        "market_cap": 55000000,           // month-peak observed/verified MC
        "holder_strength": 0.83, "volume_strength": 0.79, "market_cap_strength": 0.61,
        "market_cap_growth_pct": 210, "peak_expansion_ratio": 3.1,   // info-only
        "observation_coverage_ratio": 0.72
      },
      "source_type": "best_supported_historical_performer",
      "source_reference": "…", "source_evidence": [ {name,url,claim,published_at,credibility} ],
      "confidence": "medium", "age_uncertain": false,
      "finalized_at": "…", "computed_at": "…"
    }
  ]
}
```

A denormalized (untracked) champion has `token.id = null` and **no detail page**.
`meta` carries `top_n` (3) and `weights`.

The detail API `data.monthly_champion.championships[]` (tracked tokens only) adds
`rank` + `holder_count` + `monthly_volume` + `market_cap` alongside
`performance_score` / `source_type` / `confidence` / `source_evidence` /
`age_uncertain`.

---

## 8. Frontend

- **Homepage "🏆 Monthly Top Memecoins"** — a 3×4 year calendar. Each month card
  lists the five buckets; each bucket shows up to 3 compact rows
  `🥇 $ANSEM · 92.4 · $55M MC · 45,000 holders` (🥈 / 🥉 for ranks 2 / 3). A
  tracked token links to `/memecoin/:chainId/:tokenAddress`. Empty bucket →
  "No results yet" (future) / "No verified result" (past). The `All Chains /
  Solana / Robinhood / BSC / Base / Other` filter narrows every card to one
  bucket's Top 3 (+ volume / source / confidence). Cards are compact
  (`max-height` + scroll).
- **Detail page "Monthly Top Performer"** — `August 2026 · Solana · Rank #1`,
  then Holder count / Monthly volume / Market cap (month peak) / Performance
  score / MC growth (context) / Historical source / Confidence, plus the
  `Sources` list for a researched entry. Never "best investment".

---

## 9. Historical research evidence (Step 26 — Phase 1)

Per-metric historical provenance now lives in a **child table**,
`monthly_ranking_evidence` (`MonthlyRanking::evidence()`), one row per metric per
source per ranked entry: `metric` (holders / volume / market_cap / ohlcv /
identity / pool_date), `basis` (**`observed` / `reconstructed` / `estimate`** —
never "verified"; an estimate is always labelled one and capped at `low`
confidence), and a **deterministic** `confidence` band derived by
`HistoricalConfidenceCalculator` from evidence characteristics (source
credibility, timestamp present, identity verified, basis, corroboration) — never
hand-typed. A missing metric is an **absent row**, never a fabricated `0`.

Providers declare capabilities explicitly via
`HistoricalResearchProvider::supportsMetric()`; an unsupported/failed fetch
returns `HistoricalMetricResult::unavailable()` (never an exception, never an
invented number). **Holder history has no free automated source** — for past
months it stays UNKNOWN unless an operator supplies a dated figure.
**Robinhood** is operator-seed-only (no verified provider network).
**`web_research` stays OFF.** Full detail:
[historical-research-foundation.md](historical-research-foundation.md).

Phase 1 is the typed foundation only — the ranking formula, weights, Top-3
selection, qualification and risk are **unchanged**.

---

## 10. Known limitations

- **Historical data is only as good as the seed file.** Where no operator
  research exists for a completed month/bucket, it is honestly
  `no_verified_result` — never a fabricated position, never "no data because the
  detector didn't exist" hand-waved as an excuse (the seed-file mechanism is the
  answer).
- **Provisional holder counts** are GeckoTerminal-covered chains only; a bucket
  on an unsupported chain (e.g. `robinhood`) ranks on volume + market cap alone
  (weights renormalized).
- **`month_market_cap` is a peak**, not a time-weighted average.
- **`monthly_volume_usd`** is a representative daily figure (median in-month
  `volume_h24`), not a true monthly sum.
- **Exact DexScreener historical ranks are rarely establishable** — most
  researched entries are `best_supported_historical_performer`.
