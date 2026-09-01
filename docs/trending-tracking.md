# Trending Tracking

**Status:** implemented (corrected). Near-real-time trending is the detector's
primary concept — "find what is trending on DexScreener, then aggressively filter
it for market quality and risk."

**"Trending Now" shows ONLY the TOP N of the CURRENTLY-trending, NEWLY-LAUNCHED
memecoins** that pass our approved filters — **not** every token found inside a
trending narrative. The full provider candidate universe (~400 tokens/run) is
inspected internally but never persisted, ranked, or shown.

Base docs: [trending-discovery-reconnaissance.md](trending-discovery-reconnaissance.md)
· [sprint-1-discovery.md](sprint-1-discovery.md) · [risk-screening.md](risk-screening.md).

---

## 0. Three separate concepts

| | What it is | Source | Filter |
|---|---|---|---|
| **CURRENT TRENDING** ("Trending Now") | the TOP N (default 10, max 20) memecoins trending **right now** | the latest `trending_snapshots` capture | memecoin + age ≤ 30d + **CURRENT** MC in `[$5M, $200M]` + vol > 0 + liq > 0 |
| **TRENDING HISTORY** ("Trending Yesterday") | historical observations — what trended on a past day | `daily_trending_rankings` (archive) | none at read time — a token stays even after it stops being eligible |
| **MAIN LIST** (`GET /api/memecoins`) | the filtered investable-research universe | `tokens` + qualification | **observed/verified PEAK** in `[$5M, $200M]` + age ≤ 30d + Step 24 risk screen (age ≥ 72h, LOWER/MEDIUM) |

A token can be **MAIN LIST but not currently Trending**. A token can be
**Trending but on RISK WATCH** (or too young for the Main List). These are never
merged. The Trending-Now filter narrows the **homepage view only** — it never
changes market-cap qualification, `observed_peak_market_cap`,
`historical_peak_value`, `qualification_events`, or the risk logic.

## 1. Trending source

`GET /metas/trending/v1` → `GET /metas/meta/v1/{slug}` — **documented DexScreener
APIs only** (`DexScreenerClient::trendingMetas()` / `metaBySlug()`). Up to 18
trending narratives are expanded; every member pair is a full market object
(`marketCap`, `volume.{h6,h24}`, `priceChange.{h6,h24}`, `txns.{h6,h24}`,
`liquidity.usd`, `pairCreatedAt`). Tokens are deduplicated to their
highest-liquidity pair.

Every stored record carries **`source = dexscreener_meta`**. The paid
narrative-bar ad and any non-pair entry are rejected.

**Not used:** `wss://io.dexscreener.com/dex/screener/v7/...` (the real per-pair
`trendingScoreH6/H24` — undocumented, versioned, binary frames, Cloudflare-bot-
walled), HTML scraping, browser automation, any paid third-party wrapper. See
the reconnaissance doc §4/§12.

## 2. 6H / 24H methodology

Both timeframes use the **same formula**, fed the timeframe's own market data:

| Input | 6H | 24H |
|---|---|---|
| momentum | `priceChange.h6` | `priceChange.h24` |
| volume activity | `volume.h6` | `volume.h24` |
| transaction activity | `txns.h6.buys + .sells` | `txns.h24.buys + .sells` |
| liquidity quality | `liquidity.usd` (same) | `liquidity.usd` (same) |
| persistence | recent-capture appearance ratio (same) | (same) |

Each **eligible** token is scored once per timeframe, then ranked
(`trend_rank`, dense, 1..N) by score descending with a deterministic
`strcmp(chain:addr)` tie-break.

## 2b. Trending-Now pipeline (the correction)

Cheap filters first, then rank — never score tokens that a hard filter already
removed:

```
DEXSCREENER TRENDING SOURCE  (/metas/trending/v1 -> /metas/meta/v1/{slug}, ~400 tokens)
        ↓
DEDUPLICATE  (one representative pair per chain+token)
        ↓
MEMECOIN FILTER      MemecoinClassifier -> TRUE only  (FALSE / UNKNOWN excluded)
        ↓
CURRENT MARKET FILTER   CURRENT market_cap in [$5M, $200M]  AND liquidity > 0  AND volume > 0
        ↓
enrich the (now small) NEW memecoins -> Token + MarketSnapshot   (only to get a real earliest_pair_created_at)
        ↓
AGE FILTER <= 30d    on the REAL earliest_pair_created_at (Token model); age UNKNOWN -> excluded (do not guess)
        ↓
TREND SCORE          TrackedTrendScorer, per timeframe (6h / 24h) — market cap NOT a component
        ↓
RANK                 tracked_trend_score DESC, tie-break token_key ASC
        ↓
PERSIST              trending_snapshots + daily_trending_rankings  (ELIGIBLE only, capped at
                     MEMECOIN_TREND_MAX_CANDIDATES = 60 for chain-filter / history headroom)
        ↓
API returns TOP N    default MEMECOIN_TREND_TOP_N = 10, max MEMECOIN_TREND_TOP_MAX = 20
```

**MemecoinClassifier** (`config/trending.php` → `memecoin.*`, deterministic, no
AI):

- **FALSE** — a deny-list symbol (stablecoin / wrapped `w*` / blue-chip /
  liquid-staking), or a deny-list name substring (`staked ether`, `wrapped `,
  `usd coin`, `lending protocol`, …). Excluded from Trending Now.
- **TRUE** — member of a **meme-narrative** trending meta (`dog`, `cat`, `frog`,
  `animal`, `degen`, `trump`, `elon`, `brainrot`, `slang`, `tiktok`, …) **or** a
  meme keyword in the name/symbol (`pepe`, `doge`, `wif`, `bonk`, `inu`, …).
  Included.
- **UNKNOWN** — no strong meme signal, no non-meme signal (e.g. in an `ai` /
  `nft` / `defi` utility meta with a plain name). Excluded — the spec: "if
  ambiguous, allow only if other memecoin signals are strong", and a strong
  signal would have made it TRUE.

`is_memecoin_candidate` (TRUE / UNKNOWN / FALSE) is stored on each snapshot for
transparency.

**CURRENT market cap vs the Main List.** Trending Now's `[$5M, $200M]` band is on
the token's **current** `marketCap` from the trending-meta response. The MAIN
LIST band is on the **observed/verified peak** and is unchanged. A memecoin that
pumped to $400M then fell to $80M is *not* in Trending Now (current filter is a
band, not a floor) but its Main-List qualification is untouched.

**Age.** Uses the real `earliest_pair_created_at` from the `Token` model
(`min(pairCreatedAt)` across all pairs, established by discovery or by the
Trending enrichment step). The single meta-pair `pairCreatedAt` is only a *loose
pre-gate* for the enrich decision. A token whose age cannot be established from a
`Token` row is **excluded** ("do not guess").

## 3. Internal trend score (`tracked_trend_score`)

Transparent, deterministic, config-driven (`config/trending.php`), **no AI**:

```
tracked_trend_score = 100 · ( Σ weight_i · component_i ) / Σ weight_i      (0..100)

  momentum              0.5 · (1 + tanh(price_change_pct / ref_momentum_pct))   w=0.30
  volume_activity       v / (v + ref_volume_usd)                                w=0.28
  transaction_activity  t / (t + ref_txns)                                      w=0.18
  liquidity_quality     l / (l + ref_liquidity_usd)                             w=0.12
  persistence           appearances / persistence_window                        w=0.12
```

**Market cap is not an input** — a big token does not out-rank a smaller one for
being big. A missing/unusable metric contributes `unavailable_component` (0.25),
below the 0.5 midpoint, so incomplete data lowers the score. `trend_score_components`
is stored on every snapshot so any rank is explainable.

## 4. Why the proprietary DexScreener score is unavailable

DexScreener publishes a documented REST API precisely so consumers don't hit
`io.dexscreener.com`. The per-pair `trendingScore*` lives only on that
undocumented WebSocket: versioned (`v5` → `v7` observed), custom binary frames,
and behind Cloudflare Bot Management (every non-browser request → HTTP 403). It
fails every one of *free / no key / no scraping / no browser automation /
maintainable*. We reproduce DexScreener's **trending narratives** exactly and
build our **own** transparent per-token timeframe score. UI wording: **"Tracked
Trending"** / **"Trending by DexScreener market signals"** — never "DexScreener
Trending Score".

## 5. 5-minute refresh

`memecoins:collect-trending`, scheduled `*/MEMECOIN_TREND_REFRESH_MINUTES` (default
`*/5`), `withoutOverlapping(10)`. UI wording: **"Updated every ~5 minutes"** /
**"Near real-time tracking"** — **not** "tick-level real-time". Provider load per
run: ~19 calls on the 60/min bucket (`/metas/*`, 60s response cache) + ≤ 40
`/token-pairs/v1` calls on the 300/min bucket (new-token enrichment), bounded
concurrency. Risk screening is **not** run here — it keeps its own 6h/token
cooldown.

## 6. Historical snapshots (`trending_snapshots`)

One row per `(chain_id, token_address, timeframe, capture_bucket)` where
`capture_bucket = floor(now / (refresh_minutes·60))·(refresh_minutes·60)` (epoch
seconds). Only **eligible** trending memecoins get a snapshot (memecoin + age ≤
30d + current MC in band + activity). Re-running inside one bucket **upserts**
the row; a new bucket **appends**. `trend_appearances` is the count of distinct
prior capture buckets in the persistence window (the persistence-component
input).

**Filtering never destroys history.** A token that was eligible when captured
keeps every snapshot it ever had — even after it stops trending, ages past 30
days, or falls out of the current-MC band. Only `memecoins:cleanup-trending`
(retention) ever deletes a snapshot. So a token trending yesterday remains fully
queryable via the history endpoint even though it is no longer in "Trending
Now".

Indexes: `(chain_id, token_address, timeframe, captured_at)`,
`(timeframe, captured_at)`, `(token_address, captured_at)`,
`(timeframe, capture_bucket, trend_rank)`.

## 7. Yesterday archive (`daily_trending_rankings`)

One row per `(date, chain_bucket, timeframe, token_address)`, upserted every run:
`best_rank = MIN`, `best_score = MAX`, `peak_market_cap / peak_volume /
peak_liquidity = MAX`, `appearances += 1`, `first_seen_at` preserved,
`last_seen_at = now`. This is what "Trending Yesterday" reads — enough to
reconstruct Top 10 / Top 20 for any retained day. `GET /api/memecoins/trending/history`
reads it **only** and never recomputes.

## 8. Chain grouping

Five fixed **display buckets** (`App\Services\Ranking\ChainBucket`):
`solana` / `robinhood` / `bsc` / `base` / `other`. `other` is a display bucket
only — the token keeps its real `chain_id`; only the ranking row's
`chain_bucket` ever says `"other"`. There is no official `GET /chains` endpoint,
so chains are discovered from the candidates actually seen; unsupported chain ids
are never invented.

## 9. Reported volume

"Top Volume by Chain" and "Chain Market Activity" both use **`volume_h24` from
each tracked token's latest `market_snapshot`** — the representative
(highest-liquidity) pair's figure, **one number per token**. No free provider
gives us verified organic volume, so this is always labelled **"Reported
Volume"** and never claimed to be organic / real human volume.

## 10. Chain aggregation rule

`daily_chain_activity` is materialised per bucket per day on every
`collect-trending` run:

```
for each Token with a market_snapshot in the last MEMECOIN_CHAIN_ACTIVITY_ACTIVE_HOURS (48):
    s = token.latestSnapshot
    if not MarketIntegrityGate::passes(s.volume_h24, s.liquidity_usd, s.market_cap, s.txns_h24): skip
    bucket = ChainBucket::forChain(token.chain_id)
    total_volume_usd[bucket]    += s.volume_h24          # ONE figure per token — never per-pair
    total_liquidity_usd[bucket] += s.liquidity_usd
    active_token_count[bucket]  += 1
    top_token[bucket] = argmax(s.volume_h24)
```

`GET /api/memecoins/chain-activity` returns today's row per bucket +
`volume_change_pct` vs yesterday's row (null when there is no prior row).

**Market-integrity gate** (`MarketIntegrityGate`, `config/trending.php`
`integrity.*`) — excludes: zero/missing liquidity, zero/missing transactions,
impossible market cap (`> $1e12`), and an extreme `volume/liquidity` ratio
(`> 75`, a wash-trade shape). It removes anomalies; it does **not** certify the
survivor as organic.

## 11. Trending ↔ risk separation

```
Trending Now (memecoin + age ≤ 30d + CURRENT MC $5M–$200M + vol/liq > 0)
          →  Market-cap Qualification (observed/verified PEAK $5M–$200M, age ≤ 30d)
          →  Risk Screening (Step 24)
          →  MAIN LIST   (LOWER/MEDIUM, age ≥ 72h, enough data, no hard filter)
             or RISK WATCH (everything else qualified — visible, flagged)
```

Trending never makes a token "safe" or "a good investment" and never lets it
skip the filters. A **high-risk** trending token still appears in "Trending Now"
(trending is attention, not endorsement) but **never** enters the MAIN LIST, and
it stays visible on **RISK WATCH** with its risk level, failed checks, trend
rank, timeframe and last-trending time. A **POKEGYM-style** 2-hour-old token with
a $23M MC *can* rank #1 in Trending Now yet still have `main_list_eligible =
false` (age < 72h + risk). Because trending does not refresh a risk scan, a scan
older than `MEMECOIN_RISK_SCAN_COOLDOWN_HOURS` (6h) shows **"RISK CHECK STALE"**
and is never silently treated as safe.

## 12. Limitations

- **Not DexScreener's ranking.** The 18 trending narratives are DexScreener-
  curated; a trending token in none of them is missed. Our `tracked_trend_score`
  is a defensible stand-in, not the proprietary number.
- **Small by design.** The eligible set is often only a handful — trending +
  memecoin + newly-launched (≤ 30d) + current MC $5M–$200M is a narrow
  intersection. Showing 2–10 rows is correct; showing 100 was the bug.
- **Age gap.** A brand-new memecoin becomes eligible only once its real
  `earliest_pair_created_at` is known (via the Trending enrich step or the
  10-minute discovery run) — a few minutes of latency.
- **Classifier is heuristic.** `MemecoinClassifier` is a deny-list / meme-meta /
  keyword model, not a project registry. An ambiguous token in a utility meta is
  excluded (conservative); the lists are config-tunable.
- **No verified organic volume.** "Reported Volume" is provider-reported; the
  integrity gate removes obvious anomalies only.
- **~5-minute granularity**, not tick-level. A token can trend and vanish
  between captures — but any capture that landed is kept.
- **New trending token latency.** A brand-new trending token is enriched to a
  `Token` on the next 5-minute `collect-trending` run (or the 10-minute
  `discover` run), so it may be in Trending a few minutes before it can reach
  the Main List.
- **`daily_chain_activity` day-over-day** needs a prior day's row to exist —
  `volume_change_pct` is null until there is history.

---

## Commands / APIs / config

| | |
|---|---|
| `memecoins:collect-trending` | `*/5 * * * *`, `withoutOverlapping(10)` — collect → **memecoin filter** → **current-MC filter** → enrich new memecoins → **age filter** → score 6h+24h (ELIGIBLE only) → snapshots + daily rollup → chain-activity rollup |
| `memecoins:cleanup-trending [--days=] [--daily-days=] [--dry-run]` | `dailyAt('00:40')` — prune `trending_snapshots` > 30d, `daily_*` > 365d |
| `GET /api/memecoins/trending?timeframe=6h\|24h&chain=&limit=` | **TOP N** (default `top_n` 10, max `top_max` 20) of the latest capture; read-time eligibility guard; `rank` renumbered 1..N; `meta.filters` + `meta.top_n`; joins risk + `risk_check_stale` |
| `GET /api/memecoins/trending/history?date=&timeframe=&chain=` | `daily_trending_rankings` only; default date = yesterday; **may return more rows than Trending Now** |
| `GET /api/memecoins/top-volume?chain=` | top 5 per bucket by reported `volume_h24`, integrity-gated; all 5 buckets |
| `GET /api/memecoins/chain-activity` | per-bucket totals from `daily_chain_activity` + day-over-day delta |
| `config/trending.php` | all tuning — `top_n` / `top_max`, `eligibility.*` (age / current-MC band), `memecoin.*` (classifier lists), `score.*`, `persistence.*`, `collect.*`, `retention.*`, `volume.*`, `integrity.*`, `risk_stale_hours` |

`GET /api/memecoins/trending` response `meta` block:

```json
{
  "timeframe": "6h", "count": 10, "top_n": 10, "top_max": 20,
  "filters": {
    "memecoin_only": true, "max_age_days": 30,
    "min_current_market_cap": 5000000, "max_current_market_cap": 200000000,
    "volume_required": true, "liquidity_required": true
  }
}
```

All read APIs are **PostgreSQL-only** — no DexScreener / GoPlus / provider call,
no WebSocket, no scraping, no recompute, no write. No pagination — the result is
intentionally small (Top 10, max 20).
