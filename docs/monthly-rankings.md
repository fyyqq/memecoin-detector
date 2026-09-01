# Monthly Top Memecoins (Step 22, corrected · historical backfill Step 25)

For **every calendar month** the homepage shows the **top-1 performing memecoin
inside each of FIVE fixed display buckets**:

| Bucket | Chain |
|---|---|
| `solana` | `chain_id = solana` |
| `robinhood` | `chain_id = robinhood` |
| `bsc` | `chain_id = bsc` |
| `base` | `chain_id = base` |
| `other` | **every other** `chain_id` |

So the unique key is **`(year, month, chain_bucket)`** — at most `12 × 5 = 60`
champions a year. There is **no global monthly winner** and **no unlimited
per-chain rows**. A token keeps its real `chain_id`; only
`monthly_rankings.chain_bucket` ever says `"other"`. The bucket list is fixed in
`App\Services\Ranking\ChainBucket` and is intentionally not env-configurable.

Past months are **finalized** (or `best_supported_candidate` /
`no_verified_champion`) and immutable during normal operation; the current month
is **provisional** per bucket; future months are **future** with no token.

For **completed months before our detector launched** (late August 2026) we
**actively research external / historical market sources**
(`memecoins:research-monthly-champions`) rather than returning "no champion" by
default. Historical champion selection is a **performance** ranking and — like
the live path — **never uses the risk score and never uses AI**; a historical
performer is not removed because its *current* risk is HIGH. We **never
fabricate** a winner, a date, a source, or a DexScreener rank.

---

## 1. What "champion" means

> The single memecoin with the strongest **supported performance** within the
> eligible universe for that month **and chain bucket**.

It is **NOT** the highest current market cap, the largest token, the highest
liquidity, or the first token to cross $5M. The score **primarily rewards
relative market-cap growth** (baseline → peak within the month).

- **Risk score is never used** to select the winner.
- **AI is never used** to select the winner.
- The 0–100 score is **not a prediction of future returns** and never an
  investment recommendation. The UI says *"observed MC growth"*, never
  *"profit"* / *"ROI"* / *"best return"* / *"safest coin"*.

---

## 2. Eligible universe (unchanged from Step 19)

A token/month is a candidate for a bucket only when **all** of:

1. `ChainBucket::forChain($token->chain_id)` equals the bucket;
2. token age ≤ 30 days **at the observation** (per-snapshot, not "now");
3. an **observed or verified** market cap ≥ **$5M** was reached that month;
4. the verified/observed peak market cap is ≤ **$200M** (§7);
5. `volume_h24 > 0` on the eligible snapshots;
6. `liquidity_usd > 0` on the eligible snapshots.

`HISTORICAL_ESTIMATE` (FDV basis) does **not** qualify. `UNKNOWN` does **not**
qualify. **FDV is never used as market cap.** The token-level universe check
reuses the Step 19 rule exactly (`HistoricalPeakEvidence` `CURRENT_OBSERVATION` /
`HISTORICAL_VERIFIED` with `peak_value_usd` in `[$5M, $200M]`, **or**
`observed_peak_market_cap` in that band, and
`GREATEST(observed_peak, historical_peak_value) ≤ $200M`).

---

## 3. Monthly observation window + performance

`MonthWindow` for month *M* is the half-open UTC interval
`[first-of-M 00:00, first-of-(M+1) 00:00)`. Only `MarketSnapshot`s whose
`observed_at` falls inside it **and** are taken while the token age ≤ 30 days
count.

- **`baseline_market_cap`** = the `market_cap` of the **earliest eligible
  snapshot in the month**.
- **`peak_market_cap`** = the **highest** eligible-snapshot `market_cap` in the
  month. Never FDV, never an estimate.
- `market_cap_growth_pct = (peak − baseline) / baseline × 100`
- `peak_expansion_ratio  = peak / baseline` (≥ 1)

### The 0–100 score (unchanged formula — `config/ranking.php`)

```
performance_score = 100 · ( w_growth    · growth_score
                          + w_expansion · expansion_score
                          + w_activity  · activity_score )   (clamped to [0, 100])
```

Default weights: `w_growth = 0.60`, `w_expansion = 0.25`, `w_activity = 0.15`.
Deterministic capped-log normalization:

```
growth_score    = min(1, ln(1 + growth_pct/100) / ln(1 + growth_reference))   // growth_reference 20
expansion_score = min(1, ln(peak_expansion_ratio) / ln(expansion_reference))  // expansion_reference 25
```

Activity (15%, **supporting evidence only**) combines the **median** of the
month's eligible-snapshot `volume_h24`, `liquidity_usd`, `txns_h24`,
`|price_change_h24|`, each capped-log-normalized. Activity can never let a flat
token beat a real grower. Everything is deterministic — same inputs → same
score.

### Observation coverage

```
observation_coverage_ratio = min(1, eligible_observations / expected_observations)
```
where `expected_observations` is over the token's *possible* in-month window at
the detector's cadence. Below `MEMECOIN_MONTHLY_MIN_OBSERVATION_COVERAGE`
(default **0.25**) a candidate cannot **finalize** — but a real token that led
its bucket only thinly is still recorded (§5).

### Tie-break (deterministic)

Highest `performance_score`, then: higher `market_cap_growth_pct` → higher
`peak_market_cap` → higher `observation_coverage_ratio` → higher
`observation_count` → **lower `token_id`**.

---

## 4. Per-bucket status

| status | meaning |
|---|---|
| `provisional` | the **current** month; recomputed daily from our live internal observations; may still change |
| `finalized` | a completed past month with a **defensible** winner and sufficient evidence — an internal-observed eligible winner, a source-established exact DexScreener rank, or a fully-supported historical performer (≥ 2 strong sources, peak MC in band, computable growth, known trading age). `finalized_at` set; immutable during normal operation |
| `best_supported_candidate` | a completed past month where a **real token clearly led the bucket** but the evidence is incomplete — thin internal observation coverage, or historical research with a derived baseline / a single source / an uncertain trading age. |
| `no_verified_champion` | a completed past month with **no defensible candidate** — nothing in the universe, and no historical research candidate could be identity-resolved and validated. `token_id = null`. **Never a fabricated winner.** |
| `future` | the month has not happened yet; `token_id = null` |

`best_supported_candidate` and `no_verified_champion` (with `finalized_at` set)
are also **settled** — a normal scheduler run never replaces them; only `--force`
does.

Month-level status (API `data[i].status`): `future` if the whole month is in the
future, `provisional` if it is the current month, else `finalized` — even when
some of its buckets are `no_verified_champion`.

---

## 5. Historical provenance (Step 25)

Every settled bucket with a champion carries:

| field | values |
|---|---|
| `source_type` | `internal_observed` · **`exact_dexscreener_rank`** · **`best_supported_historical_performer`** |
| `source_reference` | a short human string (e.g. `internal snapshots: 42 obs, coverage 0.61` or `crypto.news: 4 source(s) incl. strong`) |
| `source_evidence` | a **short list** of `{ name, url, claim, published_at, credibility }` — the research provenance for a historically-backfilled champion (`[]` for an internal-observed row). **No page bodies are stored.** |
| `age_uncertain` | `true` when the ≤ 30-day trading-age window could not be established from evidence — caps confidence and blocks `finalized` |
| `confidence` | `high` · `medium` · `low` |

`source_type`:

- **`internal_observed`** — from our own `MarketSnapshot`s. Confidence: eligible
  winner coverage ≥ 0.5 → `high`, else `medium`; a `best_supported_candidate`
  from thin internal data → `low`.
- **`exact_dexscreener_rank`** — a source **directly establishes** the
  DexScreener historical rank for the month/bucket (e.g. archived DexScreener
  evidence). Used **only** then.
- **`best_supported_historical_performer`** — the best-supported #1 performer
  from historical research: identity-resolved (name + symbol + chain, ideally a
  contract address), MARKET CAP (never FDV) in `[$5M, $200M]`, timing within the
  month, defensible ≤ 30-day trading age. `finalized` when ≥ 2 strong sources +
  computable growth + a known age; else `best_supported_candidate`. Confidence
  is **capped at the operator-verified suggestion** and lowered further for a
  derived baseline / a single strong source / `age_uncertain`.

**We never claim an exact DexScreener historical rank unless a source
establishes it.** The DexScreener **public API does not expose a historical
monthly Trending leaderboard**, and search-engine result pages must not be
scraped. Where historical evidence is incomplete the honest outcome is
`best_supported_candidate` ("best-supported monthly performer") or
`no_verified_champion` — never a guessed archived ranking, never
*"Top DexScreener coin"*.

### Historical source priority

`primary/official market data` > `reputable historical market-data provider` >
`archived DexScreener evidence` > `reputable crypto reporting` >
`established secondary source` > `low-quality` (supporting only). Many
low-quality pages never outweigh one strong primary source.

### Entity resolution

A historical candidate is **never** identified from a symbol alone (ticker
collisions). It must carry a name **and** symbol **and** chain, ideally a
contract address, and its declared bucket and its real `chain_id` must both map
to the same bucket. Unresolvable identity → the candidate is dropped.

---

## 6. Commands

### `memecoins:finalize-monthly-champion` (deterministic, internal only)

```
php artisan memecoins:finalize-monthly-champion [--year=YYYY --month=M] [--chain=<bucket>] [--force]
```

- **No arguments** → the safe daily pass: refresh **every bucket** of the current
  provisional month and settle **every not-yet-settled bucket** of the previous
  completed month (so on September 1 it settles all five August buckets, never
  September).
- `--year` / `--month` → settle that month's five buckets; `--chain=` restricts
  to one bucket. Refuses an incomplete month unless `--force`.
- `--force` → recompute settled rows too.

**Never** calls a provider or web search.

**Scheduled** daily at `00:20` (`withoutOverlapping(60)`, reuses the existing
`scheduler` container — **no new container**). Running daily is self-healing.

### `memecoins:research-monthly-champions` — Historical Monthly Champion Backfill (Step 25)

```
php artisan memecoins:research-monthly-champions --year=YYYY --month=M [--chain=<bucket>] [--force]
```

For a **completed past** month + bucket, actively research external / historical
market sources to identify the best-supported #1 performer — instead of
returning "no champion" just because our detector did not exist before late
August 2026. It is the **only** place external research is allowed.

- `--year --month` — required. Researches all five buckets for that month.
- `--chain=<bucket>` — research one bucket (a safe unit for a controlled batch).
- `--force` — re-research a `finalized` bucket, or backfill the **current**
  month (normally the current month stays `provisional` from live internal
  data).
- Progress output, per bucket:
  `Solana → researching` … `Solana → best_supported_candidate — FOO (…)`.

Flow per bucket (`MonthlyChampionResearchService`):

1. **gather** candidates from the ordered providers
   (`MEMECOIN_MONTHLY_RESEARCH_PROVIDERS`, default `internal_observed,seed_file`):
   - **`internal_observed`** — our own `MarketSnapshot`s (always on);
   - **`seed_file`** — operator-verified historical candidates from
     `MEMECOIN_MONTHLY_RESEARCH_SEED_PATH` (default
     `storage/app/monthly-champion-candidates.json`). This is the bridge from
     **manual internet research**: an operator investigates reputable historical
     market sources, resolves token identity, and records
     `{ year, month, chain_bucket, name, symbol, chain_id, token_address,
     baseline/peak market cap, volume_usd, launch_date, age_uncertain,
     source_type, confidence, sources:[{name,url,claim,published_at,credibility}],
     explanation }`. The file is **never auto-generated from search snippets**;
   - **`web_research`** — an OFF-by-default automated extension point (there is
     no free official API for historical monthly trending);
2. **resolve entity identity** (name + symbol + chain, ideally an address);
3. **validate** eligibility as far as the evidence allows — $5M–$200M MARKET CAP
   (never FDV), the right bucket, the right month, ≤ 30-day trading age
   (`age_uncertain` + capped confidence when the launch date is not defensible);
4. **rank** survivors with the deterministic performance formula
   (`MonthlyPerformanceCalculator::scoreHistorical` — same weights + capped-log
   references as the internal path; growth needs both baseline + peak);
5. **classify** → `finalized` / `best_supported_candidate` /
   `no_verified_champion` (§4), with `source_type`, `source_evidence`,
   `age_uncertain` and a confidence **capped at the operator's suggestion**.

The command **never** claims an exact DexScreener rank unless a source
establishes it, **never** invents a candidate / URL / date, **never** scrapes
search-engine result pages, and **never** reads the current Risk Assessment
(historical champion selection is a **performance** ranking — a historical
performer is not removed because its *current* risk is HIGH). **Not scheduled** —
run on demand, one month (five buckets) or one bucket at a time.

A historically-researched champion that is **not in our `tokens` table** stores
its identity denormalized on the ranking row (`champion_name` / `champion_symbol`
/ `champion_chain_id` / `champion_token_address` / `champion_image_url`);
`token_id` links to a `Token` only when we actually track it. `tokens.chain_id`
is never mutated.

---

## 7. The $5M–$200M rule

- **Floor** — the month's peak eligible snapshot MC must be ≥ $5M.
- **Ceiling** — if **any** snapshot in the month had `market_cap > $200M`, the
  token is not eligible for that month even if it later fell back; and a token
  whose all-time verified/observed peak ever exceeded $200M is not in the
  universe at all.

---

## 8. Database — `monthly_rankings`

One row per `(year, month, chain_bucket)` (**unique**) → ≤ 60 rows a year:
`chain_bucket`, `token_id` (nullable), `champion_name` / `champion_symbol` /
`champion_chain_id` / `champion_token_address` / `champion_image_url` (nullable —
a historically-researched champion not in our `tokens` table), `status`,
`performance_score`, `baseline_market_cap`, `peak_market_cap`,
`market_cap_growth_pct`, `peak_expansion_ratio`, `activity_score`,
`observation_count`, `observation_coverage_ratio`, `scoring_breakdown` (json),
`source_type`, `source_reference`, `source_evidence` (json —
`[{name,url,claim,published_at,credibility}]`), `age_uncertain` (bool),
`confidence`, `finalized_at`, `computed_at`, timestamps. Index on
`(year, chain_bucket)`.

`Token hasMany monthlyRankings`; `MonthlyRanking belongsTo Token`.
`MonthlyRanking::championIdentity()` returns the champion for the API whether it
is a tracked `Token` or a denormalized historical winner. Writing is done
**only** by `memecoins:finalize-monthly-champion` and
`memecoins:research-monthly-champions`. Only tokens present in `tokens` have a
detail page; a denormalized historical champion is display-only.

---

## 9. API

`GET /api/memecoins/monthly-champions?year=YYYY` — **read-only**. Reads
`monthly_rankings` only — never recomputes, never queries `market_snapshots`,
never calls a provider, **never performs web research**.

**Always 12 month entries** (January … December). **Each month ALWAYS contains
exactly the five buckets** — never omitted:

```jsonc
{
  "data": [
    {
      "year": 2026, "month": 8, "month_name": "August",
      "status": "finalized",                       // month-level: finalized | provisional | future
      "champions": {
        "solana":    { "chain_bucket": "solana", "status": "best_supported_candidate",
                       "token": { "id": 55, "symbol": "DOGE", "name": "…",
                                  "chain_id": "solana", "chain_bucket": "solana",
                                  "token_address": "…", "image_url": "…" },
                       "performance": { "score": 8.4, "baseline_market_cap": …, "peak_market_cap": …,
                                        "market_cap_growth_pct": 0, "peak_expansion_ratio": 1,
                                        "activity_score": …, "observation_count": 3,
                                        "observation_coverage_ratio": 0.06 },
                       "source_type": "internal_observed", "source_reference": "…",
                       "source_evidence": [], "age_uncertain": false,
                       "confidence": "low", "finalized_at": "…", "computed_at": "…" },
        "robinhood": { "chain_bucket": "robinhood", "status": "finalized",
                       "token": { "id": null, "symbol": "CASHCAT", "name": "CASHCAT",
                                  "chain_id": "robinhood", "chain_bucket": "robinhood",
                                  "token_address": "0x…", "image_url": null },
                       "performance": { "market_cap_growth_pct": 2097, "peak_market_cap": 156000000, … },
                       "source_type": "best_supported_historical_performer",
                       "source_evidence": [ { "name": "crypto.news — …", "url": "https://…",
                                              "claim": "…", "published_at": "2026-07-17",
                                              "credibility": "reputable_reporting" }, … ],
                       "age_uncertain": false, "confidence": "medium", … },
        "bsc":       { "chain_bucket": "bsc", "status": "finalized", "token": {…}, "performance": {…}, … },
        "base":      { "chain_bucket": "base", "status": "no_verified_champion", "token": null, "performance": null,
                       "source_type": null, "source_evidence": [], "age_uncertain": false, … },
        "other":     { "chain_bucket": "other", "status": "no_verified_champion", "token": null, "performance": null, … }
      }
    }
    // … 12 total
  ],
  "meta": { "year": 2026, "count": 12, "current_year": 2026, "current_month": 9,
            "buckets": ["solana","robinhood","bsc","base","other"],
            "source": "monthly_rankings", "selection_note": "…" }
}
```

- A stored bucket row is returned as-is.
- A bucket with no stored row is synthesized: `provisional` for the current
  month, `future` for a future month, `no_verified_champion` for a past month.
  Never a fabricated winner.

Each bucket entry also carries **`source_evidence`** (`[]` unless a
historically-backfilled champion) and **`age_uncertain`**. A denormalized
historical champion's `token.id` is `null`.

The detail endpoint (`GET /api/memecoins/{chainId}/{tokenAddress}`) exposes
`data.monthly_champion` = `{ is_champion, championships: [...] }` — the
`(month, chain_bucket)` slots **this tracked token** led, newest first, each
with `chain_bucket`, `status`, `source_type`, `source_reference`,
`source_evidence`, `age_uncertain`, `confidence`. Read-only — never recomputes,
never researches.

---

## 10. Frontend

Homepage **"🏆 Monthly Chain Champions"** — a **3×4 year calendar**. Each month
card lists the **five chain buckets** (Solana / Robinhood / BSC / Base / Other),
each showing `🥇 $SYMBOL` + `+X% MC growth`, or a status label:
**Finalized** · **Best-supported** · **No verified champion** · **No champion
yet** (future). An `age uncertain` tag appears when the trading-age window is
uncertain. A **chain filter** (`All Chains` / `Solana` / `Robinhood` / `BSC` /
`Base` / `Other`, default *All Chains*) narrows every month card to a single
bucket; the single-bucket view also shows peak MC, real `chain_id`, confidence,
and the source-type label (e.g. *Historical research* / *Internal observed
data* / *DexScreener historical rank*). Only a **tracked** champion (one in our
`tokens` table) is a link to `/memecoin/:chainId/:tokenAddress`; a
historically-backfilled champion is display-only. React never fetches an image
from a provider. The section intro states plainly that completed months before
detector launch are backfilled from researched historical sources and that an
incomplete-evidence bucket shows "Best-supported" or "No verified champion" —
never a fabricated winner or a claimed exact DexScreener rank.

Month card status: current month → **Provisional**, past → **Finalized**, future
→ **Upcoming**.

Detail page **"Monthly Chain Champion"** section (only when a **tracked** token
led a bucket): `🥇 <Month> <Year> — <Bucket> #1`, status, chain bucket, observed
MC growth, baseline / peak MC, performance score, observation coverage,
**historical source**, **confidence**, and a **"Trading age: Uncertain"** row
when applicable — plus a **"Sources"** list of `{name (linked), published_at,
claim}` for a historically-backfilled champion. With *"Not a best investment,
best return, or safest coin — a monthly observed-performance record per chain
only."* A non-`internal_observed`, non-`exact_dexscreener_rank` champion adds
*"Best-supported monthly performer — not a 'Top DexScreener coin' claim"*; a
`best_supported_candidate` adds *"This is the best-supported historical
candidate based on available evidence; it is not necessarily an exact
DexScreener historical rank."*

---

## 11. Known limitations

- **The detector's own history starts in late August 2026.** Earlier months are
  backfilled from **researched historical evidence** (Step 25). Where no
  candidate could be identity-resolved and validated ($5M–$200M MARKET CAP,
  right bucket, right month, ≤ 30-day trading age), the honest outcome is
  `no_verified_champion` — not a fabricated winner. In the live backfill
  (Jan–Aug 2026) only **one** external candidate met the bar: **CASHCAT**
  (Robinhood Chain, July 2026 — the chain launched July 1, 2026 and CASHCAT was
  its first meme token, peaking ~$156M within its first trading week;
  corroborated by CoinDesk, Fortune and crypto.news). Every other past bucket is
  `no_verified_champion` — the accessible historical sources for other months
  are SEO listicles about large-cap tokens, monthly price-gainer lists dominated
  by established coins, or tokens whose peak was outside the requested month —
  none identity-resolvable to an eligible new memecoin.
- **No free API exposes historical monthly Trending**, so a historical winner is
  `best_supported_historical_performer` (or `exact_dexscreener_rank` only with
  archived DexScreener evidence). The automated `web_research` provider is a
  documented, OFF-by-default extension point; real historical research flows
  through the operator-curated `seed_file`.
- **Historical baselines are often derived** from a reported % growth to a
  reported peak, which caps confidence at `medium`.
- **Trending membership is a proxy** — we do not persist per-token discovery
  provenance; a token in `tokens` / `market_snapshots` that passes the Step 19
  universe is treated as tracked-trending for the month.
- **Coverage depends on our observation cadence.** A token that trended hard for
  a few days of a long month may fall below the 0.25 coverage floor and be
  recorded as `best_supported_candidate` rather than `finalized`.
- **Baseline is the earliest in-month eligible snapshot**, not the true monthly
  low or launch price — the growth figure is conservative if we first observed
  the token mid-run.
- **Cross-month tokens** — a 30-day window can straddle two months; each month
  is scored independently on its own in-window snapshots, in its own bucket.
- **One row per (month, bucket)** — no history of a provisional month's earlier
  values is kept.
