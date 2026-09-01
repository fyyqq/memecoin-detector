# Current System & Canonical Roadmap

> **Audit date:** 2026-09-01 · **Repo HEAD:** `d7b8cd6` · **Branch:** `main` (up to date with `origin/main`, working tree clean)
> **Method:** direct inspection of the live repository, PostgreSQL schema + data, `schedule:list`, `route:list`, `docker compose`, running API responses, and the React source. Not derived from conversation summaries.
> **Scope of this document:** read-only audit + canonical roadmap. No code, migrations, config, frontend, tests, commits or pushes were made — only this file was created.

> **Update 2026-09-01 (post-audit):** the near-real-time **Trending Tracking**
> feature was removed at the product owner's request — the "🔥 Top Trending
> Memecoins" and "📜 Yesterday's Trending" homepage sections plus their backend
> (`memecoins:collect-trending` / `cleanup-trending`, `trending_snapshots` +
> `daily_trending_rankings` tables, `tracked_trend_score`, `MemecoinClassifier`,
> `GET /api/memecoins/trending` + `/trending/history`, the Main List / Risk Watch
> `trend` block, and the discovery trend-rank prioritization signal). Also
> removed the pre-existing dead `App\Services\Trend\*` + `config/trend.php`.
> **Kept:** trending-**meta** *discovery*, **📊 Chain Market Activity** and
> **💧 Top Volume by Chain** — `ChainActivityRollup` now runs inside
> `memecoins:discover`.
>
> **Update 2026-09-01 (2nd, post-audit):** the **⚠️ Risk Watch** section was also
> removed — the homepage list + `GET /api/memecoins/risk-watch` +
> `RiskWatchController` + `RiskWatchSection` + `fetchRiskWatch` + the `RiskWatchRow`
> types. The deterministic risk screen still runs (`memecoins:screen-risk`),
> still **gates the MAIN LIST** (`MainListDecision`), and still shows on the
> detail page (`data.risk_assessment`) and as Main-List row chips. A
> market-cap-qualified token that fails the screen is now simply **not shown in
> any list** (its assessment is on its detail page). `RiskAssessment::isRiskWatch()`
> deleted; the `screen-risk` run telemetry key `risk_watch` renamed to
> `not_main_list_eligible`.
>
> Net effect of both updates: sections **2, 5, 6, 7, 9, 13 (A–F, H)** below
> describe the pre-removal state; the homepage is now **Recently Crossed $5M →
> Main Memecoin List → Chain Market Activity → Top Volume by Chain → Monthly
> Chain Champions** (5 sections), the scheduler has **7** commands, and the API
> has **9** routes. The **§14 recommended next step (Risk Phase 3 — Identity &
> Market-Integrity)** stands, minus its "route to RISK WATCH" wording — a
> flagged token is now excluded from the Main List rather than rerouted.
>
> **Update 2026-09-01 (3rd — Step 25, Monthly Top 3):** "Monthly Chain
> Champions" became **"Monthly Top Memecoins"** — the top **3** performers per
> `(year, month, chain_bucket)` instead of one. `monthly_rankings` is now
> **unique on `(year, month, chain_bucket, rank)`** (`rank ∈ {1,2,3}`, ≤ 180
> rows/year) with new columns `rank` / `holder_count` / `monthly_volume_usd` /
> `month_market_cap` / `holder_strength` / `volume_strength` /
> `market_cap_strength` / `holder_checked_at`. Selection is no longer
> market-cap-growth-weighted — it is a **participation** score
> `100·Σ(w·strength)/Σ(w)` over the KNOWN components, weights
> `holder 0.40 / volume 0.35 / market_cap 0.25` (`config/ranking.php`,
> env-configurable), `strength(x, ref) = min(1, ln(1+x)/ln(1+ref))`; a `null`
> holder count is UNKNOWN and drops out (never zeroed, never a current count for
> a past month); a researched MC-only candidate is halved
> (`market_cap_only_penalty`). `growth` / `expansion` / `activity` are retained
> as **info-only** context. The daily `memecoins:finalize-monthly-champion` now
> also runs a lightweight **monthly holder pass** (`MonthlyHolderCollector` →
> GeckoTerminal `/info`, current provisional month only, ≤ 25/run, 20h per-token
> cooldown, monthly-max, **no `market_snapshots` change**). Statuses collapsed
> to `provisional | finalized | future | no_verified_result` (the old
> `best_supported_candidate` / `no_verified_champion` are gone — "best-supported"
> is now `confidence: low` on a `finalized` row). The API returns each bucket as
> `{status, entries[0..3]}` with `meta.top_n` + `meta.weights`. Live backfill
> result unchanged in spirit: **CASHCAT → July / Robinhood rank 1**
> (`best_supported_historical_performer`, `confidence: medium`, MC-only →
> score 50); every other completed past bucket is honestly `no_verified_result`.
> Sections **7, 13-H**, the schema tables and issue **#4** below describe the
> pre-Step-25 one-winner model.

---

## 1. Executive Summary

The Memecoin Detector is a **mature multi-feature intelligence platform**, well past the "smallest useful detector" framing still used in `CLAUDE.md` and `README.md`. 14 commits, 24 app migrations (all applied), 16 domain tables, 12 read/ingest API routes, 9 scheduled commands, 10 `memecoins:*` artisan commands, 457 passing backend tests, a clean frontend build, and a healthy 4-service Docker stack.

**What works end-to-end today:**
- Trending-meta-first discovery → normalize → age filter → persist `Token` + `MarketSnapshot` → observed-peak tracking → CURRENT_OBSERVATION qualification.
- Near-real-time trending (6H/24H), top-N eligible memecoins, persistent 5-minute history + daily archive, top-volume-by-chain, chain-activity.
- Deterministic risk screening across 7 signal groups, MAIN LIST vs RISK WATCH partitioning.
- Recently-crossed-$5M qualification events.
- Pump **detection** (deterministic) + **evidence** collection.
- Token detail page (13 sections), live DexScreener chart, copy-CA, qualification timeline.
- Monthly-champions API + command (5 chain buckets).

**What is degraded or dormant right now:**
- **All AI output is `failed`** — `pump_explanations` (13/13 failed) and `token_narrative_reports` (16/16 failed) because no `ANTHROPIC_API_KEY` is configured in this environment. Code is correct (graceful fail, no fabrication) but produces zero usable intelligence.
- **Historical qualification yields almost only UNKNOWN** — 125 of 132 `historical_peak_evidences` rows are `UNKNOWN`, **0 `HISTORICAL_VERIFIED`**. The CoinGecko/GeckoTerminal path is running but not producing verified historical peaks (likely free-tier rate limiting / no key).
- **Monthly champions has no data** — `monthly_rankings` is empty (dev DB was reset; the daily finalizer + on-demand historical backfill have not repopulated it). The homepage grid renders all placeholders.
- **Identity / ticker-squatting and flat-price / missing-info detection are NOT implemented** — the "DOGE on Solana" class of suspicious token is not flagged for identity mismatch (see §10 #6, §13 G/H).
- **Dead code:** `App\Services\Trend\*` + `config/trend.php` + `tests/Unit/Trend/` — the old 30-day trend score, never wired, superseded by `App\Services\Trending\TrackedTrendScorer`.

**Recommended next step (exactly one):** **Phase 3 completion — Identity & Market-Integrity risk signals.** See §14.

---

## 2. Current Production Pipeline

Two independent scheduled ingestion loops write PostgreSQL; a set of PostgreSQL-only read APIs feed React.

### 2.1 Discovery loop — `memecoins:discover` (every 10 min)

`App\Services\DexScreener\DexScreenerDiscoveryService::discover()`

```
DexScreener  GET /metas/trending/v1 → GET /metas/meta/v1/{slug} ×18   [primary]
             GET /token-profiles/latest/v1                            [secondary]
             GET /token-boosts/latest/v1 + /token-boosts/top/v1       [secondary]
             GET /latest/dex/search (SearchTermEngine)                [fallback, OFF by default]
   ↓ collectCandidates()  — union + dedupe on (chain_id, lower(token_address))
   ↓ PRE-FILTER (on meta pair market data — before enrichment):
        marketCap present & > 0 & ≤ $200M · volume.h24 > 0 · liquidity.usd > 0
        · pairCreatedAt present · loose pair age ≤ 35d
   ↓ prioritizeCandidates()  — trending_meta > recent trend_rank/appearances > multi-meta
        > profile > boost > search hits > freshness > token key   (MARKET CAP is NOT a signal)
   ↓ candidate cap (500) → enrichment cap (120)
   ↓ ENRICH  DexScreener GET /token-pairs/v1/{chain}/{token}  (bounded concurrent batch)
   ↓ NORMALIZE  DexScreenerNormalizer → TokenCandidateData (representative pair = max liquidity.usd)
   ↓ STRICT AGE FILTER  earliest_pair_created_at = min(pairCreatedAt) across all pairs; ≤ 30d
   ↓ PERSIST  TokenObservationService → Token (upsert on chain_id+token_address) + MarketSnapshot
        + maintain observed_peak_market_cap (raised only when a higher CURRENT MC is seen)
   ↓ HISTORICAL LOOKUP  HistoricalQualificationService
        CoinGecko  → HISTORICAL_VERIFIED (market-cap basis)          [degraded: 0 verified in dev]
        GeckoTerminal  → HISTORICAL_ESTIMATE (peak price × total supply = FDV basis; informational)
        (only for tokens not already qualified on observed peak; 6h re-lookup cooldown; per-run budget)
   ↓ QUALIFICATION  HistoricalPeakEvidence::qualifies($5M, $200M)
        qualifies iff GREATEST(observed_peak, historical_peak_value) ∈ [$5M, $200M]
        AND status ∈ {CURRENT_OBSERVATION, HISTORICAL_VERIFIED}   (ESTIMATE / UNKNOWN never qualify)
   ↓ RECORD QUALIFICATION EVENTS  QualificationEventRecorder → qualification_events ("$5M crossing")
   ↓ (evidence for pump events is a separate loop — see 2.3)
   → IngestionRun row recorded (observability only)
```

### 2.2 Trending loop — `memecoins:collect-trending` (every 5 min)

`App\Services\Trending\TrendingCollectionService::collect()`

```
DexScreener  GET /metas/trending/v1 → GET /metas/meta/v1/{slug} ×18   (60s response cache, ~19 calls/60-min bucket)
   ↓ TrendingMetaCollector  — dedupe to one representative pair per (chain, token) (~400 tokens)
   ↓ MEMECOIN FILTER  MemecoinClassifier  → TRUE only
        FALSE = deny-list symbol (stablecoin/wrapped/blue-chip/LST) or deny-list name pattern
        UNKNOWN = no meme-narrative meta + no meme keyword  (both excluded)
   ↓ CURRENT MARKET FILTER  CURRENT marketCap ∈ [$5M, $200M] · liquidity > 0 · (h6 or h24 volume) > 0
   ↓ enrich the small NEW-memecoin subset  DexScreener GET /token-pairs/v1  (≤ 40/run, only to get real earliest_pair_created_at + a Token row)
   ↓ STRICT AGE FILTER  real earliest_pair_created_at ≤ 30d (Token model); age unknown → excluded (do not guess)
   ↓ TrackedTrendScorer  — score each ELIGIBLE token per timeframe (6h + 24h)
        score = 100·Σ(weight·component) over momentum / volume_activity / transaction_activity
        / liquidity_quality / persistence   (MARKET CAP is NOT a component; no AI)
   ↓ rank per timeframe → trend_rank (dense 1..N among eligible)
   ↓ TrendingSnapshotRecorder  → trending_snapshots  (ELIGIBLE only, ≤ 60/timeframe;
        unique (chain_id, token_address, timeframe, capture_bucket); a re-run in one 5-min bucket upserts)
   ↓ DailyTrendingRollup  → daily_trending_rankings  (best_rank MIN, best_score MAX, peak_* MAX, appearances++)
   ↓ ChainActivityRollup  → daily_chain_activity  (per bucket: Σ latest-snapshot volume/liquidity, integrity-gated)
```

Trending does **not** run historical qualification or risk screening. Filtering never deletes a stored snapshot.

### 2.3 Downstream analysis loops (all reuse the `scheduler` container)

| Loop | Command | Cadence | External | Status |
|---|---|---|---|---|
| Pump detection | `memecoins:detect-pumps` | `5,15,25,…` | none (reads `market_snapshots`) | **DONE** — 13 events in dev |
| Risk screening | `memecoins:screen-risk` | `6,16,26,…` | **GoPlus** + **GeckoTerminal `/info`** + **DexScreener `/token-pairs/v1`** | **DONE** (7 groups) — 7 assessments in dev |
| Evidence collection | `memecoins:collect-evidence` | `8,18,28,…` | **GDELT 2.1 DOC API** (only) | **DONE** — 117 evidences; 0 news (GDELT unreachable in dev) |
| AI pump explanation | `memecoins:explain-pumps` | `9,19,29,…` | **Anthropic** | **BROKEN-IN-ENV** — 13/13 `failed` (no API key) |
| Narrative research | `memecoins:research-narratives` | hourly `0 * * * *` | **GDELT** + **Anthropic** | **BROKEN-IN-ENV** — 16/16 `failed` (no API key + GDELT unreachable) |
| Monthly finalize | `memecoins:finalize-monthly-champion` | daily `00:20` | none (reads `market_snapshots`) | **DONE (code)** — 0 rows written since DB reset |
| Monthly research | `memecoins:research-monthly-champions` | on-demand only | seed file (operator research) | **PARTIAL** — mechanism DONE, not executed in this env |
| Trending cleanup | `memecoins:cleanup-trending` | daily `00:40` | none | **DONE** |

### 2.4 Where each external provider is used

| Provider | Used by | Purpose |
|---|---|---|
| **DexScreener** (`api.dexscreener.com`) | `DexScreenerClient` (discovery + trending), `DexScreenerLiquidityProbe` (risk) | trending metas, token-pairs enrichment, keyword search (off), liquidity structure |
| **CoinGecko** | `CoinGeckoClient` → `HistoricalQualificationService` | `HISTORICAL_VERIFIED` market-cap peak (cold-start). *Degraded — see §10 #4* |
| **GeckoTerminal** | `GeckoTerminalClient` → `HistoricalQualificationService`; `GeckoTerminalInfoClient` → `RiskScreeningService` | `HISTORICAL_ESTIMATE` (FDV basis, informational); risk `/info` (holder buckets, Solana authorities, honeypot flag) |
| **GoPlus** | `GoPlusSecurityClient` → `RiskScreeningService` | `token_security` + `rugpull_detecting` (EVM); `solana/token_security` |
| **GDELT** | `GdeltNewsClient` (evidence); `GdeltNarrativeResearchProvider` (narrative) | timestamped news facts around pump events; narrative source material. *Unreachable in dev* |
| **Anthropic** | `AnthropicPumpExplanationProvider`; `AnthropicNarrativeExplanationProvider` | interpret stored `Evidence` into a pump explanation; synthesize origin/popularity narrative. *No key in this env → all `failed`* |
| **The undocumented `io.dexscreener.com` WebSocket** | **never** — deliberately not referenced anywhere (see `docs/trending-discovery-reconnaissance.md`) |

---

## 3. Current Data Architecture

| Model | Migration | Written by | Read by | Purpose | Dependencies |
|---|---|---|---|---|---|
| `Token` | `…000001`, `…000005`, `…000011` | `TokenObservationService` (discovery + trending enrich) | every read API, all analysis loops | canonical memecoin identity `(chain_id, token_address)`; `observed_peak_market_cap` (OUR snapshot peak), `historical_peak_value`/`_status` (verified/observed headline), `historical_estimate_fdv_usd` (FDV basis), pool age, links | root entity |
| `MarketSnapshot` | `…000002` | `TokenObservationService` | detail API, `MemecoinListController`, `RiskScreeningService`, `PumpDetectionService`, `ChainActivityRollup`, `TopVolumeController` | one market observation per token per ~10-min run (price, MC, FDV, liq, vol, txns, buys/sells, primary pair) | `Token` |
| `IngestionRun` | `…000003`, `…000006`, `…000012` | `DexScreenerDiscoveryService` | `MemecoinDiscoveryStatusController` | one discovery execution; funnel counts + trending-meta coverage; observability only | none |
| `HistoricalPeakEvidence` | `…000004`, `…000011`, `…000014` | `HistoricalQualificationService` | `MemecoinResource`, `MemecoinDetailResource`, `Token::scopeMarketCapQualified` (via mirrored columns) | one re-evaluable qualification verdict per token: `CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` / `HISTORICAL_ESTIMATE` / `UNKNOWN` | `Token`, CoinGecko, GeckoTerminal |
| `QualificationEvent` | `…000013` | `QualificationEventRecorder` (discovery) | `RecentlyCrossedController`, `MemecoinResource`, `MemecoinDetailResource` | "$5M crossing" — `crossed_at` per `(token_id, type)`; idempotent; never rewritten | `Token`, `HistoricalPeakEvidence` |
| `RiskAssessment` | `…000018` | `RiskSnapshotRecorder` (`memecoins:screen-risk`) | `MemecoinListController`, `RiskWatchController`, `TrendingController`, `MemecoinDetailResource`, `MainListDecision` | one CURRENT deterministic risk verdict per token; `risk_level`, `risk_score`, `data_completeness`, `hard_override_signal`, `main_list_eligible` | `Token`, GoPlus, GeckoTerminal, DexScreener |
| `RiskSignal` | `…000019` | `RiskSnapshotRecorder` | `RiskWatchController`, `MemecoinResource`, `MemecoinDetailResource`, `MainListDecision` | structured tri-state signal (`MEASURED`/`BAD`/`UNKNOWN`/`NOT_AVAILABLE`) behind an assessment; replaced on every rescan | `RiskAssessment` |
| `PumpEvent` | `…000007` | `PumpEventRecorder` (`memecoins:detect-pumps`) | detail API, `EvidenceCollectionService`, `PumpExplanationService`, `RiskScreeningService` (ChartShape) | one detected observed-series pump; `started/peak/ended_at`, MC/price %, detection score, confidence | `Token`, `MarketSnapshot` |
| `Evidence` | `…000008`, `…000009` | `EvidenceRecorder` (`memecoins:collect-evidence`) | detail API, `PumpExplanationService`, `InternalEvidenceResearchProvider` | one timestamped FACT around a pump event; never asserts causality | `PumpEvent`, `Token`, GDELT |
| `PumpExplanation` | `…000010` | `PumpExplanationService` (`memecoins:explain-pumps`) | detail API | one AI interpretation per pump event; cites evidence ids | `PumpEvent`, `Evidence`, Anthropic. **All `failed` in dev** |
| `TokenNarrativeReport` | `…000015` | `NarrativeResearchService` (`memecoins:research-narratives`) | detail API | per-token origin + popularity synthesis | `Token`, `Evidence`, GDELT, Anthropic. **All `failed` in dev** |
| `TokenNarrativeSource` | `…000016` | `NarrativeResearchService` | detail API | concise source metadata + claim behind a narrative report (persisted before the AI call) | `TokenNarrativeReport`, `Token` |
| `TrendingSnapshot` | `…000021`, `…000024` | `TrendingSnapshotRecorder` (`memecoins:collect-trending`) | `TrendingController`, `MemecoinResource`/`RiskWatchController` (trend block), `DexScreenerDiscoveryService::prioritizeCandidates` | 5-min capture of an ELIGIBLE trending memecoin per timeframe; `is_memecoin_candidate`, `tracked_trend_score`, `trend_rank`, current market fields | `Token` (nullable), DexScreener |
| `DailyTrendingRanking` | `…000022` | `DailyTrendingRollup` | `TrendingHistoryController` | daily trending archive per `(date, chain_bucket, timeframe, token_address)` | `Token` (nullable) |
| `DailyChainActivity` | `…000023` | `ChainActivityRollup` | `ChainActivityController` | materialised per `(date, chain_bucket)`: reported 24h volume, liquidity, active count, top token | `Token`, `MarketSnapshot` |
| `MonthlyRanking` | `…000017`, `…000020` | `MonthlyChampionService` (`finalize`) + `MonthlyChampionResearchService` (`research`) | `MonthlyChampionsController`, `MemecoinDetailResource` | one champion per `(year, month, chain_bucket)` — ≤ 60/year; `internal_observed` / `best_supported_historical_performer` / `exact_dexscreener_rank`; denormalized `champion_*` for untracked historical winners | `Token` (nullable), `market_snapshots`, seed file. **0 rows in dev** |

Every table listed is migrated and the schema matches its model's `$fillable`. `Services/Trend/*` DTOs (`TrendInputs`/`TrendScore`) are **not** persisted anywhere.

---

## 4. Current Database

PostgreSQL 16, database `memecoin` (dev), `memecoin_test` (RefreshDatabase tests). **27 migrations applied** (24 app + 3 Laravel). Note migrations `…000017` and `…000018/019` ran out of numeric order historically but all are present.

| Table | Purpose | Writer(s) | Reader(s) | Rows (dev) | Key indexes | Potential issue |
|---|---|---|---|---|---|---|
| `tokens` | canonical identity + peak state + links | `TokenObservationService` | everything | 133 | `UNIQUE(chain_id, token_address)`, `(chain_id)` | dev DB recently reset (was 727) — light data |
| `market_snapshots` | market observation series | `TokenObservationService` | list/detail/risk/pump/activity | 965 | `(token_id, observed_at)` | no retention/pruning — grows unbounded |
| `ingestion_runs` | discovery observability | `DexScreenerDiscoveryService` | discovery-status API | 11 (seq at 100) | none | id sequence far ahead of row count (harmless) |
| `historical_peak_evidences` | qualification verdict | `HistoricalQualificationService` | list/detail + qualification scope | 132: **125 UNKNOWN, 7 CURRENT_OBSERVATION, 0 HISTORICAL_VERIFIED** | `(status)` | **verified-historical path non-functional in practice** |
| `qualification_events` | "$5M crossing" | `QualificationEventRecorder` | recently-crossed, list, detail | 6 | `UNIQUE(token_id, type)`, `(crossed_at)` | — |
| `risk_assessments` | current risk verdict | `RiskSnapshotRecorder` | list/risk-watch/trending/detail | 7: **3 LOWER/completed, 4 UNKNOWN/partial** | `UNIQUE(token_id)`, `(risk_level)`, `(main_list_eligible)` | only 7 screened (cooldown + `MAX_TOKENS_PER_RUN=15`) |
| `risk_signals` | structured signals | `RiskSnapshotRecorder` | risk-watch, list, detail | 174 | `UNIQUE(risk_assessment_id, signal_key)` | — |
| `pump_events` | detected pumps | `PumpEventRecorder` | detail/evidence/explanation/risk | 13 | (per migration) | — |
| `evidences` | pump facts | `EvidenceRecorder` | detail/explanation/narrative | 117: **90 internal, 27 dexscreener, 0 news** | `(pump_event_id, dedupe_hash)` | GDELT news = 0 (dev network) |
| `pump_explanations` | AI pump interpretation | `PumpExplanationService` | detail | **13, all `failed`** | `UNIQUE(pump_event_id)` | no `ANTHROPIC_API_KEY` |
| `token_narrative_reports` | origin + popularity | `NarrativeResearchService` | detail | **16, all `failed`** | `UNIQUE(token_id)` | no `ANTHROPIC_API_KEY` + GDELT unreachable |
| `token_narrative_sources` | narrative sources | `NarrativeResearchService` | detail | 150 | `(token_narrative_report_id, dedupe_hash)` | sources persist even when synthesis fails (by design) |
| `trending_snapshots` | 5-min trending history | `TrendingSnapshotRecorder` | trending API, list trend block, discovery prioritizer | 4832: **4800 `is_memecoin_candidate` NULL (pre-correction), 36 TRUE**; latest 6h bucket = 2 rows | `UNIQUE(chain_id, token_address, timeframe, capture_bucket)`, `(timeframe, capture_bucket, trend_rank)`, `(token_address, captured_at)`, `(timeframe, captured_at)`, `(chain_id, token_address, timeframe, captured_at)` | 4800 legacy broad rows will age out via 30d retention |
| `daily_trending_rankings` | daily trending archive | `DailyTrendingRollup` | trending/history API | 605 | `UNIQUE(date, chain_bucket, timeframe, token_address)`, `(date, chain_bucket, timeframe, best_rank)` | mix of pre/post-correction data |
| `daily_chain_activity` | chain activity rollup | `ChainActivityRollup` | chain-activity API | 5 | `UNIQUE(date, chain_bucket)` | day-over-day delta null until 2+ days of data |
| `monthly_rankings` | monthly chain champions | `MonthlyChampion*Service` | monthly-champions API, detail | **0** | `UNIQUE(year, month, chain_bucket)`, `(year, chain_bucket)` | **no data — API synthesizes all placeholders** |

Laravel infra tables present: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` (queue tables unused — synchronous execution, no Horizon).

---

## 5. Current API

All routes are `GET` and live in `backend/routes/api.php`. All are **read-only PostgreSQL** except `/memecoins/discover` (heavy ingestion). None of the read routes call an external provider.

| Method | Path | R/W | External | Purpose | Frontend consumer | Status |
|---|---|---|---|---|---|---|
| GET | `/api/health` | R | none | liveness | — | **DONE** |
| GET | `/api/memecoins` | R | none | **MAIN LIST** — market-cap qualified **AND** `MainListDecision::eligible` (age ≥ 72h, LOWER/MEDIUM, completeness ≥ 0.5, no hard filter). `?sort=peak_market_cap\|recent_crossing`, `?chain=`, `?limit=`. Rows carry qualification, risk, `trend` block. | `MemecoinTable` (Dashboard §3) | **DONE** |
| GET | `/api/memecoins/discover` | **W** | **DexScreener** (+ CoinGecko/GeckoTerminal in the pipeline) | manual/debug trigger of the full discovery pipeline; writes `ingestion_runs`, `tokens`, `market_snapshots`, evidence | — (debug only) | **DONE** |
| GET | `/api/memecoins/discovery-status` | R | none | latest `ingestion_runs` summary + trending-meta coverage + source counts | — (no consumer) | **DONE (unwired)** |
| GET | `/api/memecoins/recently-crossed` | R | none | tokens whose representative `QualificationEvent.crossed_at` is within `?hours=` (default 48, max 168). Rows appear even if current MC < $5M. | `RecentlyCrossedSection` (Dashboard §2) | **DONE** |
| GET | `/api/memecoins/monthly-champions` | R | none | always 12 months × 5 buckets; reads `monthly_rankings` only, synthesizes `provisional`/`future`/`no_verified_champion` when no row. `?year=`. | `MonthlyChampions` (Dashboard §7) | **DONE (code); empty data** |
| GET | `/api/memecoins/risk-watch` | R | none | market-cap qualified but **fails** the MAIN LIST screen (HIGH/CRITICAL/UNKNOWN/too-young/hard filter). `failed_signals[]`, `reasons[]`, `trend` block. | `RiskWatchSection` (Dashboard §4) | **DONE** |
| GET | `/api/memecoins/trending` | R | none | **TOP N** (default `top_n`=10, max `top_max`=20; no pagination) of the latest capture; read-time eligibility guard (memecoin + age ≤ 30d + CURRENT MC $5M–$200M + vol/liq > 0); `rank` renumbered 1..N; `meta.top_n` + `meta.filters`. `?timeframe=6h\|24h`, `?chain=`. | `TrendingNow` (Dashboard §1) | **DONE** |
| GET | `/api/memecoins/trending/history` | R | none | `daily_trending_rankings` only; default `?date=` = yesterday; never recomputes; may return more rows than Trending Now. | `TrendingHistory` (Dashboard §8) | **DONE** |
| GET | `/api/memecoins/top-volume` | R | none | top 5 per chain bucket by reported `volume_h24` from latest snapshot, behind `MarketIntegrityGate` (liq > 0, txns > 0, MC ≤ $1e12, vol/liq ≤ 75); all 5 buckets. `?chain=`. | `TopVolume` (Dashboard §6) | **DONE** |
| GET | `/api/memecoins/chain-activity` | R | none | per-bucket totals from `daily_chain_activity` + day-over-day `volume_change_pct` (null with no prior row). | `ChainActivity` (Dashboard §5) | **DONE** |
| GET | `/api/memecoins/{chainId}/{tokenAddress}` | R | none | full token detail — nested `qualification` / `historical_estimate` / `observed` / `latest` / `pair` / `snapshots` (≤50) / `pump_intelligence` / `qualification_timeline` / `monthly_champion` / `risk_assessment` / `token_narrative` / `provenance`. Any stored `Token` resolves (not gated by qualification). Miss → `404`. | `MemecoinDetail` page | **DONE** (AI/narrative sub-blocks show `pending`/`failed`) |

**No routes exist for:** trading, portfolio, notifications, auth, watchlists, or any write endpoint other than `/discover`. Correct per product scope.

---

## 6. Current Scheduler

`schedule:work` in the `scheduler` container. From live `schedule:list`:

| # | Cron | Command | withoutOverlapping | Purpose | Provider deps |
|---|---|---|---|---|---|
| 1 | `*/10 * * * *` | `memecoins:discover --trigger=scheduled` | 15 | discovery + snapshot ingestion + historical qualification + `$5M` crossing events | DexScreener, CoinGecko, GeckoTerminal |
| 2 | `*/5 * * * *` | `memecoins:collect-trending` | 10 | near-real-time trending (6h+24h) → eligible top-N snapshots + daily archive + chain activity | DexScreener |
| 3 | `5,15,25,35,45,55` | `memecoins:detect-pumps` | 15 | deterministic pump detection over stored snapshots | none |
| 4 | `6,16,26,36,46,56` | `memecoins:screen-risk` | 20 | deterministic risk screening (6h/token cooldown, ≤15 tokens/run) | GoPlus, GeckoTerminal, DexScreener |
| 5 | `8,18,28,38,48,58` | `memecoins:collect-evidence` | 15 | facts around recent pump events | GDELT |
| 6 | `9,19,29,39,49,59` | `memecoins:explain-pumps` | 15 | AI pump explanation | Anthropic |
| 7 | `0 * * * *` (hourly) | `memecoins:research-narratives` | 30 | token origin + popularity synthesis (24h/token cooldown) | GDELT, Anthropic |
| 8 | `20 0 * * *` (daily) | `memecoins:finalize-monthly-champion` | 60 | compute current provisional month + settle previous month | none |
| 9 | `40 0 * * *` (daily) | `memecoins:cleanup-trending` | 30 | prune `trending_snapshots` > 30d, daily rollups > 365d | none |

**Per-interval ordering within a 10-min window:** discovery (`:00`) → pump detection (`:05`) → risk screen (`:06`) → evidence (`:08`) → AI explanation (`:09`). Trending runs independently every `:00 :05 :10 …`. No Redis/queue — all synchronous. `memecoins:research-monthly-champions` is **not scheduled** (on-demand operator tool).

---

## 7. Current Homepage

`frontend/src/pages/Dashboard.tsx`. Sections render in this exact order:

| # | Section | Component | Reads | Notes |
|---|---|---|---|---|
| 1 | **🔥 Top Trending Memecoins** | `TrendingNow` | `/trending` | tabs `[6H] [24H]`, chain filter `[All][Solana][Robinhood][BSC][Base][Other]`, `#` (1..N) / Token / Chain / **Age** / MC / Volume(TF) / Liquidity / Trend score / Risk chip; "Updated ~Nm ago"; `main list` tag; `RISK CHECK STALE`; intro states the eligibility filters. Max ~10 rows. |
| 2 | **🔥 Recently Crossed $5M** | `RecentlyCrossedSection` | `/recently-crossed` | compact table Token / Chain / Crossed / Current MC / Peak MC / `ACTIVE`\|`COOLED`. |
| 3 | **🟢 Main Memecoin List** | `MemecoinTable` (inside a `qualified-list` section) | `/memecoins` | Sort control (Peak market cap / Recent crossing), 60s auto-refresh; Token / Chain / Age / Current MC / Peak MC / **Trend** / Risk (chip + first summary phrase) / 24h Volume / Liquidity; `CURRENT`/`VERIFIED` badge + copy-CA per row. |
| 4 | **⚠️ Risk Watch** | `RiskWatchSection` | `/risk-watch` | market-cap qualified but failed the screen: Token / Chain / Age / Current MC / Peak MC / Risk (`HIGH`/`CRITICAL`/`RISK UNKNOWN`) / Why flagged / trend block. Never renders "SAFE". |
| 5 | **📊 Chain Market Activity** | `ChainActivity` | `/chain-activity` | 5 cards (Solana/Robinhood/BSC/Base/Other): 24H **Reported Volume** / Liquidity / Active Tokens / Top Volume token / Δ vs previous day. |
| 6 | **💧 Top Volume by Chain** | `TopVolume` | `/top-volume` | per-bucket top-5 list: token / 24H reported volume / liquidity / MC / risk chip. Buckets with 0 eligible tokens are hidden. |
| 7 | **🏆 Monthly Chain Champions** | `MonthlyChampions` | `/monthly-champions` | 3×4 calendar, each card lists 5 chain buckets + status label; chain filter narrows to one bucket. **All placeholders in dev (empty table).** |
| 8 | **📜 Yesterday's Trending** | `TrendingHistory` | `/trending/history` | tabs `[6H] [24H]` + chain filter; best rank / token / chain / peak MC / peak vol / trend / appearances. Historical archive — not recomputed. |

Header: title "Memecoin Detector", subtitle "Trending first — then filtered for market quality & risk", global chain filter + Refresh. Footer: provenance + "reads persisted data from this app's API only … never opens a WebSocket". React auto-refresh: 60s for most feeds, 5min for Trending Now. Known cosmetic: React StrictMode double-mount causes `net::ERR_ABORTED` on first-render fetches (retried, harmless).

---

## 8. Current Detail Page

`frontend/src/pages/MemecoinDetail.tsx`, route `/memecoin/:chainId/:tokenAddress`. Reads `/api/memecoins/{chain}/{address}` only. Sections in order:

| # | Section | Content | Status |
|---|---|---|---|
| 1 | **Header** | name · symbol · chain_id · middle-truncated CA + **copy button** + back link | **DONE** |
| 2 | **Live market chart** | embedded DexScreener `<iframe>` from `chain_id` + `latest.primary_pair_address` (format-checked, never `token_address`); "Live chart unavailable" when no pair | **DONE** |
| 3 | **Market overview** | stat cards: current MC, Observed Peak MC vs Qualification Peak, liquidity, volume, price, FDV; `ESTIMATE` → "Estimated — FDV basis" | **DONE** |
| 4 | **Why is this token on the list?** | status-coloured qualification evidence card; `UNKNOWN` → "insufficient historical observation", never "did not reach $5M" | **DONE** |
| 5 | **Qualification timeline** | Crossed $5M / Crossing type / MC at crossing / Current MC / Peak MC; below-$5M → "remains qualified because it previously crossed" | **DONE** |
| 6 | **Monthly Chain Champion** | only when a **tracked** token led a bucket: `🥇 <Month> <Year> — <Bucket> #1`, status, growth, baseline/peak MC, score, coverage, source + confidence, Sources list | **DONE (renders when data exists)** |
| 7 | **Risk Assessment** | risk chip (`LOWER`/`MEDIUM`/`HIGH`/`CRITICAL`/`RISK UNKNOWN`) / score / completeness / last screened / hard-override flag; 7 signal groups (Contract Security / Exit Safety / Holder Distribution / Liquidity / Pump-Dump / Market Structure / Age) with ✅/⚠/❓ per signal, expandable; "pending" when unscreened; disclaimer "heuristic filter, not a guarantee of safety" | **DONE** |
| 8 | **Pump events** | timeline `started→peak` / MC % / price % / detection score+confidence / status; each expands to the persisted AI explanation + cited evidence | **DONE (detection); explanation shows `failed` note in dev** |
| 9 | **Market activity** | 24h volume, txns, buys/sells, price change | **DONE** |
| 10 | **Observation history** | sparkline + snapshot table (≤ 50, newest first) | **DONE** |
| 11 | **Token identity** | website / twitter / telegram links, image, `earliest_pair_created_at` | **DONE** |
| 12 | **Token narrative intelligence** | two-column: "Why it became popular" + "Why it was created" — headline / summary / timeline / factors / confidence / cited sources; `pending`/`partial`/`failed` → neutral note | **DONE (renders `failed` note in dev — no AI key)** |
| 13 | **Data provenance** | data source, last observed, retrieved timestamps | **DONE** |

The 13 required detail-page capabilities from the product spec (§11 of the request) are all present as sections. Missing usable output: pump **explanation** prose and narrative prose (both `failed` in this env).

---

## 9. Feature Status Matrix

| Feature | Status | Evidence |
|---|---|---|
| **Discovery — trending-meta-first** | **DONE** | `DexScreenerDiscoveryService`, scheduled `*/10`, 53 discovery tests, live: 117 raw → 4 qualified/run |
| **Discovery — keyword fallback** | **DORMANT** | `SearchTermEngine` wired but `MEMECOIN_KEYWORD_DISCOVERY_ENABLED=false` default; `search_terms_used: 0` live |
| **Historical Qualification — CURRENT_OBSERVATION** | **DONE** | 7 rows in dev; `HistoricalQualificationService` |
| **Historical Qualification — HISTORICAL_VERIFIED (CoinGecko)** | **BROKEN / PARTIAL** | **0 rows in dev**, 125 UNKNOWN; code present + tested but not producing verified peaks (no `COINGECKO_API_KEY` → free-tier limited) |
| **Historical Qualification — HISTORICAL_ESTIMATE (GeckoTerminal)** | **DONE (code); 0 rows** | informational-only by design; not being produced in dev |
| **Risk Screening — 7 signal groups + MAIN/RISK WATCH partition** | **DONE** | `RiskScreeningService`, `RiskSignalEvaluator`, `MainListDecision`; 41+ risk tests; 7 assessments live |
| **Risk Screening — identity / ticker-squatting** | **NOT IMPLEMENTED** | no `identity` group; no famous-name/wrong-chain signal (see §10 #6) |
| **Risk Screening — flat-price / dead-token / low-turnover** | **NOT IMPLEMENTED** | `market_structure` only flags HIGH turnover, not LOW/flat |
| **Risk Screening — missing project information** | **NOT IMPLEMENTED** | no signal for "no website + no socials + no description" |
| **Risk Screening — trader participation consistency** | **NOT IMPLEMENTED** | `top_trader_analysis` = `NOT_AVAILABLE` by design; no txn-count-derived heuristic |
| **Trending — 6H/24H, near-real-time, top N** | **DONE** | `TrendingCollectionService`, scheduled `*/5`, `TrendingApiTest` (15) + `TrendingCollectionTest` (21); live: 2 eligible/capture, `meta.top_n: 10` |
| **Trending — persistent history + yesterday archive** | **DONE** | `trending_snapshots` (4832 rows, 12 buckets), `daily_trending_rankings` (605), `/trending/history` |
| **Recently Crossed $5M** | **DONE** | `qualification_events` (6), `RecentlyCrossedController`, `RecentlyCrossedTest` |
| **Monthly Chain Champions — 5 buckets** | **PARTIAL (code DONE, data DORMANT)** | `MonthlyRanking`, `MonthlyChampionService`, 60+ tests; **`monthly_rankings` = 0 rows**; scheduler hasn't repopulated since DB reset |
| **Historical Monthly Backfill (internet research)** | **PARTIAL** | `MonthlyChampionResearchService` + `SeedFileMonthlyResearchProvider` + seed file (3993 B) present; `web_research` provider is an off-by-default stub; **not executed in this env** |
| **Narrative Intelligence — origin + popularity** | **BROKEN-IN-ENV** | `NarrativeResearchService` scheduled hourly, `NarrativeResearchTest` green; **16/16 reports `failed`** (no `ANTHROPIC_API_KEY`, GDELT unreachable) |
| **Pump Detection** | **DONE** | `PumpDetectionService`, scheduled, `PumpDetectionTest`; 13 events live |
| **Evidence Collection** | **DONE** | `EvidenceCollectionService`, scheduled, `EvidenceCollectionTest`; 117 evidences (0 news — GDELT unreachable in dev, degrades gracefully) |
| **AI Pump Explanation** | **BROKEN-IN-ENV** | `PumpExplanationService` scheduled, `PumpExplanationTest` green; **13/13 `failed`** (no `ANTHROPIC_API_KEY`) |
| **Live DexScreener Chart** | **DONE** | `LiveMarketChart` iframe on detail page |
| **Copy CA** | **DONE** | `CopyAddress` component (detail header + main-list rows) |
| **Qualification Timeline** | **DONE** | detail §5, `qualification_timeline` in detail API |
| **Top Volume by Chain** | **DONE** | `TopVolumeController` + `MarketIntegrityGate`; `TopVolume` component; `TrendingApiTest` covers it |
| **Chain Total Reported Volume / Activity** | **DONE** | `ChainActivityController` + `daily_chain_activity` + `ChainActivityRollup`; day-over-day delta |
| **Discovery Status endpoint** | **DONE (unwired)** | `/discovery-status` returns data; no React consumer |
| **Old 30-day `TrendScorer` + `config/trend.php`** | **DORMANT / DEAD** | `App\Services\Trend\*`, only referenced by `tests/Unit/Trend/TrendScorerTest.php`; superseded by `TrackedTrendScorer` |

---

## 10. Known Conflicts / Technical Debt

For each: **File · Current behavior · Documented behavior · Which matches the latest requirement · Recommended action.**

### #1 — Dead "30-day trend score" service
- **File:** `backend/app/Services/Trend/{TrendScorer,TrendInputs,TrendScore}.php`, `backend/config/trend.php`, `backend/tests/Unit/Trend/TrendScorerTest.php`
- **Current behavior:** exists, has a passing unit test, is **never** injected into any pipeline / controller / command / API.
- **Documented behavior:** not documented as active; `CLAUDE.md` "Tables:" line explicitly says "No … trend score …".
- **Matches requirement:** the requirement is served by `App\Services\Trending\TrackedTrendScorer` (per-timeframe, wired). The old one is superseded.
- **Recommended action:** delete the 4 files (+ its test). Zero production impact. Removes the confusing `Trend` vs `Trending` namespace collision and the duplicate `trend.php`/`trending.php` config.

### #2 — All AI output is `failed`
- **File:** `pump_explanations` (13/13 `failed`), `token_narrative_reports` (16/16 `failed`); `backend/config/ai.php` (`AI_PROVIDER=anthropic`), no `ANTHROPIC_API_KEY` in `backend/.env`.
- **Current behavior:** the scheduled commands run, call the Anthropic provider, get no key, and record `status: failed` with a concise error (no fabrication — correct).
- **Documented behavior:** `docs/pump-explanation.md` / `docs/token-narrative-intelligence.md` describe the happy path; `CLAUDE.md` notes `ANTHROPIC_API_KEY` is required.
- **Matches requirement:** requirements N/O/P (origin, popularity, pump explanation) want **usable output**. Currently there is none.
- **Recommended action:** set `ANTHROPIC_API_KEY` in the environment (config change, out of scope for code). Then re-run `memecoins:explain-pumps --force` / `memecoins:research-narratives --force` and verify. Until then, mark N/O/P as **NOT DONE (blocked on env)**.

### #3 — Historical verified qualification produces nothing
- **File:** `historical_peak_evidences` (0 `HISTORICAL_VERIFIED`, 125 `UNKNOWN`); `backend/config/historical.php` (`COINGECKO_ENABLED=true`, no `COINGECKO_API_KEY`).
- **Current behavior:** `HistoricalQualificationService` runs in the discovery pipeline, calls CoinGecko, and records `UNKNOWN` for essentially every cold-start token.
- **Documented behavior:** `docs/historical-peak-reconnaissance.md` + `CLAUDE.md` describe `HISTORICAL_VERIFIED` as a first-class qualification source.
- **Matches requirement:** requirement #4 lists `HISTORICAL_VERIFIED` as an accepted qualifier. It is effectively non-functional.
- **Recommended action:** investigate — is it the missing `COINGECKO_API_KEY` (free demo tier → 429s), a token-ID resolution problem, or a window/threshold bug? Add a diagnostic count to the ingestion run and (if key-related) document the key requirement. **Data-integrity item.**

### #4 — Monthly champions table empty
- **File:** `monthly_rankings` (0 rows); `MonthlyChampionsController` synthesizes 12×5 placeholders.
- **Current behavior:** homepage §7 renders an all-placeholder grid ("No champion yet" / "Upcoming"). `memecoins:finalize-monthly-champion` runs daily but there is little qualified snapshot history to compute from, and historical months were never backfilled in this DB.
- **Documented behavior:** `docs/monthly-rankings.md` + `CLAUDE.md` describe finalized/provisional/backfilled rows.
- **Matches requirement:** requirement #11/L wants per-bucket monthly #1s. The code is correct; there is simply no data.
- **Recommended action:** (a) let the daily finalizer accumulate `provisional` current-month rows as snapshot history grows; (b) run `memecoins:research-monthly-champions --year=… --month=…` for completed past months using operator internet research into the seed file. **History-completeness item, not a code gap.**

### #5 — `README.md` is Step-1 stale
- **File:** `README.md` — "This repository currently contains **only the local development foundation** … Feature work … lands in later sprints."
- **Current behavior:** the repo has ~15 shipped features.
- **Recommended action:** rewrite `README.md` to reflect the current system (or point it at this document + `CLAUDE.md`). Cosmetic, low priority.

### #6 — No identity / ticker-squatting risk signal ("DOGE on Solana")
- **File:** `backend/app/Services/Risk/RiskSignalEvaluator.php` — 7 groups: `contract_security`, `exit_safety`, `holder_distribution`, `liquidity`, `pump_dump`, `market_structure`, `age`. **No `identity` group.**
- **Current behavior:** a token named "Dogecoin" / symbol "DOGE" on Solana with an in-band CURRENT MC is classified by `MemecoinClassifier` as a memecoin (keyword `doge`), then risk-screened only on contract/holder/liquidity/pump-dump. If GoPlus returns clean data and holders are distributed-but-thin, it scores `holder_count` at `SEVERITY_LOW` (contributes 0.4) — **not enough to keep it off the MAIN LIST on its own.** The identity mismatch itself is never evaluated. **Partial backstops:** MC > $200M → excluded by qualification; missing GoPlus/holder data → low `data_completeness` → `RISK UNKNOWN` → RISK WATCH.
- **Documented behavior:** `docs/memecoin-risk-reconnaissance.md` discusses "famous ticker on wrong chain" as a target pattern; `CLAUDE.md` risk section lists market-integrity concepts. It is **documented as intended but not built.**
- **Matches requirement:** requirement #7 + the explicit "DOGE on Solana" callout require this. **The system currently CAN admit such a token to the MAIN LIST — HIGH PRIORITY GAP.**
- **Recommended action:** see §14 — this is the recommended next step.

### #7 — Missing market-integrity risk signals (flat price, low turnover, missing info)
- **File:** `RiskSignalEvaluator::marketStructureSignals()` — flags **high** `volume_liquidity_ratio` (turnover) and sell-dominant flow only.
- **Current behavior:** a "huge MC + flat price + near-zero activity" dead-shell token is not flagged (ChartShape covers pump-dump *shape*, not *flatness*). "No website/socials/description" is not a signal.
- **Documented behavior:** `docs/memecoin-risk-reconnaissance.md` §"Suspicious market structure" lists these.
- **Matches requirement:** requirement #7 (H). **PARTIAL — the anomaly families concentration/liquidity/pump-dump are done; flat/dead/missing-info are not.**
- **Recommended action:** bundle with §14 (same evaluator, same command, one migration for any new signal keys — actually no migration needed, `risk_signals` is key-agnostic).

### #8 — `docker-compose.yml` header comment stale
- **File:** `docker-compose.yml` lines 1–8 — "scheduler → php artisan schedule:work (runs memecoins:discover every 10 min)".
- **Current behavior:** the scheduler runs 9 commands.
- **Recommended action:** one-line comment update. Cosmetic.

### #9 — `CLAUDE.md` "Sprint 1 — Memecoin Discovery (current focus)" heading
- **File:** `CLAUDE.md` line 396.
- **Current behavior:** the "in scope / excluded from Sprint 1" list has grown to 11 items and the "excluded" list is mostly now-implemented. The project is not "Sprint 1".
- **Recommended action:** rename to "Product scope" or similar; move the per-feature detail (which is accurate) under a neutral heading. Cosmetic but affects onboarding clarity.

### #10 — 4,800 legacy `trending_snapshots` rows with `is_memecoin_candidate = NULL`
- **File:** `trending_snapshots`.
- **Current behavior:** pre-correction broad-universe rows. The read guard treats `NULL ≠ FALSE` so they'd pass the memecoin check, but they are never the "latest capture" and will age out via the 30-day retention job.
- **Matches requirement:** transient; the corrected behavior is live.
- **Recommended action:** none required. Optionally a one-off `DELETE FROM trending_snapshots WHERE is_memecoin_candidate IS NULL` to clean immediately (not necessary).

### #11 — `/api/memecoins/discovery-status` has no frontend consumer
- **File:** route exists; no React code fetches it.
- **Recommended action:** either wire a small "ingestion health" widget (low priority) or leave as a debug endpoint (acceptable).

**No conflict found** between: the $5M/$200M peak rule (code = docs = requirement #2 — floor on peak, ceiling on `GREATEST(observed_peak, historical_peak_value)`), the "Other" display bucket (`ChainBucket::forChain` keeps real `chain_id`, code = requirement #11), FDV-never-as-market-cap (enforced in normalizer + qualification + resources), or the trending-not-WebSocket rule.

---

## 11. Latest Product Requirements

These are the current source of truth (from the audit request). Restated tersely, with the current gap.

| # | Requirement | Current gap |
|---|---|---|
| 1 | **Trending-first discovery** — documented DexScreener trending/meta APIs; 6H + 24H; all chains; no WebSocket/scrape/browser | none — met |
| 2 | **Market-cap universe** — floor on **peak** (must have reached ≥ $5M; may fall below); ceiling on **qualifying peak** ≤ $200M; never FDV | none — met |
| 3 | **Age ≤ 30 days**; `pairCreatedAt` = pool evidence, not deploy date | none — met |
| 4 | `CURRENT_OBSERVATION` + `HISTORICAL_VERIFIED` qualify; `HISTORICAL_ESTIMATE` informational; `UNKNOWN` never | `HISTORICAL_VERIFIED` non-functional in practice (§10 #3) |
| 5 | **Recently crossed $5M** — persisted events; a token below $5M stays qualified | none — met |
| 6 | **Main List requires risk screening** — 9 risk concepts; levels LOWER/MEDIUM/HIGH/CRITICAL/UNKNOWN; risk ≠ "safe"/"scam probability"; Risk Watch stays visible | 3 of the 9 concepts (identity integrity, some of market integrity, trader consistency) not built (§10 #6/#7) |
| 7 | **Suspicious market structure** flags — famous name/wrong chain, huge MC + tiny holders/traders, low turnover, flat + low activity, missing info, extreme concentration, suspicious liquidity, severe pump-dump | concentration / liquidity / pump-dump done; **identity, trader-participation, low-turnover-flat, missing-info NOT done** |
| 8 | **Trending history** persists; yesterday ≠ today | none — met |
| 9 | **Top volume / chain activity** — total reported volume per chain, top 5 by chain, integrity filter, no double-count; call it "Reported Volume" | none — met |
| 10 | **Detail page** — CA+copy, chart, qualification, observed peak, historical, timeline, snapshots, pump events, pump explanation, evidence, why-popular, why-created, risk, source evidence | all sections present; pump-explanation + narrative prose empty in this env (§10 #2) |
| 11 | **Monthly = per chain bucket** (Solana/Robinhood/BSC/Base/Other), ≤ 60/yr; "Other" = display bucket, real chain_id kept; past months from research; current = provisional; future = empty; never fabricate | code matches exactly; **no data** (§10 #4) |
| 12 | **MCP** — GitHub + Notion connected | `.mcp.json` present; both configured |

---

## 12. Canonical Roadmap

Structured by capability layer, not by historical step number. Priority order follows the request's **PRIORITY RULE** (correctness → data integrity → discovery quality → risk filtering → historical completeness → intelligence → UI → optional).

### PHASE 0 — FOUNDATION · **DONE**
- **Objective:** Dockerized Laravel 12 + React 19 + PostgreSQL 16; scheduler container; `CLAUDE.md`; MCP.
- **Status:** DONE (`docker-compose.yml`, `docker/`, `.mcp.json`, 4 healthy services, `DockerComposeSchedulerTest`).
- **Next action:** de-stale `README.md` (§10 #5) and the `CLAUDE.md` "Sprint 1" heading (§10 #9). Cosmetic.
- **Why it matters:** onboarding accuracy.

### PHASE 1 — DISCOVERY · **DONE**
- **Objective:** trending-meta-first candidate discovery across all chains on documented APIs.
- **Status:** DONE — `DexScreenerDiscoveryService`, `TrendingMetaCollector`; keyword engine retained OFF by default; 53 discovery tests.
- **Next action:** none required. Optional: delete the dead `SearchTermEngine` path if keyword discovery is permanently abandoned (decision required).
- **Why it matters:** the top of the funnel; everything depends on it.

### PHASE 2 — QUALIFICATION · **PARTIAL**
- **Objective:** a token qualifies iff `GREATEST(observed_peak, historical_peak_value) ∈ [$5M, $200M]`, age ≤ 30d, via `CURRENT_OBSERVATION` or `HISTORICAL_VERIFIED`.
- **Status:** `CURRENT_OBSERVATION` **DONE**; `HISTORICAL_VERIFIED` **BROKEN in practice** (0 rows, §10 #3); `HISTORICAL_ESTIMATE` DONE-but-unused; `qualification_events` DONE.
- **Dependencies:** CoinGecko (key?) / GeckoTerminal reliability.
- **Next action:** diagnose why `HistoricalQualificationService` returns only `UNKNOWN` — add per-run diagnostics, verify CoinGecko token-ID resolution + rate-limit handling, confirm whether `COINGECKO_API_KEY` is required for the demo endpoint being used.
- **Why it matters:** data integrity — without it, any token that peaked ≥ $5M *before* the detector started tracking it is permanently invisible.

### PHASE 3 — RISK · **PARTIAL** ← **highest-priority incomplete work**
- **Objective:** the MAIN LIST admits only market-cap-qualified tokens that also pass a conservative, deterministic risk screen covering **all 9** concepts in requirement #6/#7.
- **Status:** 6 of 9 concept families DONE (`contract_security`, `exit_safety`, `holder_distribution` incl. thin-holder + concentration, `liquidity`, `pump_dump`, `age`). **NOT DONE:** identity integrity (ticker/name squatting), full market integrity (flat price / dead activity / abnormally-low turnover), trader-participation consistency.
- **Dependencies:** `RiskSignalEvaluator`, `RiskScoreCalculator`, `TokenRiskContext`, `MainListDecision` — all already exist; `risk_signals` is key-agnostic (**no migration needed**). Needs only new signal-key logic + config thresholds + weights.
- **Next action:** **implement an `identity` signal group + extend `market_structure`** — see §14.
- **Why it matters:** correctness + risk filtering. Today a mid-cap ticker-squat can reach the MAIN LIST (§10 #6). This is the one gap the product owner explicitly flagged as HIGH PRIORITY.

### PHASE 4 — TRENDING · **DONE**
- **Objective:** near-real-time (5-min) 6H/24H top-N eligible trending memecoins; persistent history; yesterday archive; top-volume; chain-activity.
- **Status:** DONE — `TrendingCollectionService` + 4 read APIs + 4 React sections; `TrendingApiTest` (15), `TrendingCollectionTest` (21), `TrackedTrendScorerTest` (7), `MemecoinClassifierTest` (8).
- **Next action:** none required. Optional cleanup: `DELETE` the 4,800 legacy `is_memecoin_candidate IS NULL` snapshots (§10 #10).
- **Why it matters:** the primary discovery-facing product surface.

### PHASE 5 — INTELLIGENCE · **BLOCKED (env) + DONE (deterministic parts)**
- **Objective:** evidence-backed pump explanations + token origin/popularity narratives.
- **Status:** pump **detection** DONE; **evidence** DONE (news degraded); pump **explanation** + **narrative** are `failed` for every row — no `ANTHROPIC_API_KEY`, GDELT unreachable in dev.
- **Dependencies:** environment config (`ANTHROPIC_API_KEY`), network reach to GDELT.
- **Next action:** configure `ANTHROPIC_API_KEY`, then `--force` re-run and verify a non-`failed` `PumpExplanation` + `TokenNarrativeReport`. This is **operational**, not a code step.
- **Why it matters:** user-facing intelligence (priority #6) — below correctness/risk. The code is done; only the key is missing.

### PHASE 6 — HISTORY · **PARTIAL**
- **Objective:** monthly chain champions (5 buckets) with researched historical backfill; trending history.
- **Status:** trending history DONE. Monthly champions: **code DONE, data absent** (`monthly_rankings` = 0). Historical backfill mechanism (seed file → `MonthlyChampionResearchService`) DONE; `web_research` is a stub; never executed here.
- **Dependencies:** accumulated qualified-snapshot history (for `provisional`); operator internet research (for past months).
- **Next action:** let the daily finalizer run; then execute `memecoins:research-monthly-champions` per completed past month with operator-verified seed data.
- **Why it matters:** historical completeness (priority #5). Not blocking core detection.

### PHASE 7 — POLISH / VALIDATION · **PARTIAL**
- **Objective:** remove dead code, de-stale docs, wire the discovery-status widget, add a data-layer verification harness (not just unit tests).
- **Status:** tests green (457 backend, lint/build clean), but there is no automated check that the *live pipeline* produces sane data (the `HISTORICAL_VERIFIED = 0` and `all-AI-failed` states went unnoticed until this audit).
- **Next action:** after Phase 3 — delete `Services/Trend/*` + `config/trend.php` + `tests/Unit/Trend/`; fix stale comments; add a `memecoins:health` diagnostic command or extend `/discovery-status` to surface qualification-source and AI-success ratios.
- **Why it matters:** maintainability + catching silent degradation early. Below all functional work.

---

## 13. Open Requirements

| Key | Requirement | Status | Detail |
|---|---|---|---|
| A | Trending 6H/24H tracked discovery | **DONE** | `TrendingCollectionService`, both timeframes scored + ranked separately |
| B | Near-real-time refresh | **DONE** | `memecoins:collect-trending` `*/5`, `withoutOverlapping(10)`; UI "Updated ~Nm ago" |
| C | Persistent trending history | **DONE** | `trending_snapshots`, 5-min capture buckets, filtering never deletes; 4832 rows / 12 buckets in dev |
| D | Yesterday trending archive | **DONE** | `daily_trending_rankings` + `GET /trending/history?date=` |
| E | Main List risk filtering | **DONE** | `MemecoinListController` → `Token::marketCapQualified` + `MainListDecision::eligible` |
| F | Risk Watch | **DONE** | `RiskWatchController` + `RiskWatchSection`; failed signals + reasons + trend block |
| G | Identity / ticker squatting detection | **NOT DONE** | no `identity` risk group; DOGE-on-Solana class not flagged (§10 #6) |
| H | Market-integrity anomaly detection | **PARTIAL** | `MarketIntegrityGate` for volume ranking DONE; risk `volume_liquidity_ratio` (high only) + `buy_share` DONE; flat-price / dead-activity / low-turnover / missing-info NOT done |
| I | Holder / trader consistency | **PARTIAL** | `holder_count` thinness + top-1/5/10 + creator concentration DONE; trader-participation = `NOT_AVAILABLE` by design, no txn-count heuristic |
| J | Top volume by chain | **DONE** | `TopVolumeController`, top 5 per bucket, integrity-gated |
| K | Chain total reported volume | **DONE** | `ChainActivityController` + `daily_chain_activity`; per-bucket totals + Δ |
| L | Monthly Chain Champions (5 buckets) | **PARTIAL** | code DONE (`MonthlyRanking` unique `(year,month,chain_bucket)`); **`monthly_rankings` = 0 rows** |
| M | Historical monthly backfill from internet | **PARTIAL** | seed-file mechanism + command DONE; `web_research` stub; not executed in this env |
| N | Token origin intelligence | **NOT DONE (blocked)** | `NarrativeResearchService` DONE + scheduled; 16/16 reports `failed` (no `ANTHROPIC_API_KEY`) |
| O | Token popularity intelligence | **NOT DONE (blocked)** | same as N |
| P | Pump explanation | **NOT DONE (blocked)** | `PumpExplanationService` DONE + scheduled; 13/13 `failed` (no `ANTHROPIC_API_KEY`) |
| Q | Live chart | **DONE** | `LiveMarketChart` DexScreener iframe on detail page |
| R | Copy CA | **DONE** | `CopyAddress` on detail header + main-list rows |
| S | Qualification timeline | **DONE** | detail §5 + `qualification_timeline` in detail API |

**Summary:** DONE = A, B, C, D, E, F, J, K, Q, R, S (11) · PARTIAL = H, I, L, M (4) · NOT DONE = G, N, O, P (4).

---

## 14. Recommended Next Step

### NEXT STEP: **Phase 3 — Identity & Market-Integrity Risk Screening**

Add a new **`identity` risk-signal group** and extend the **`market_structure`** group so that a suspicious token — the "DOGE on Solana" pattern: a famous ticker/name on a chain it does not belong to, an in-band market cap unsupported by real participation, a flat price with near-zero activity, and/or missing project information — is deterministically routed to **RISK WATCH** and can **never** enter the MAIN LIST on market cap alone.

Concretely, add these signal keys inside `RiskSignalEvaluator` (all deterministic, config-thresholded, tri-state, `internal` source unless noted):

- **`identity.famous_name_mismatch`** — the token's `name`/`symbol` matches a curated registry of well-known assets (`{name, symbol, canonical_chains[]}`) but its `chain_id` is not a canonical chain for that asset. `BAD` / `SEVERITY_HIGH`, hard-override → `HIGH`.
- **`identity.missing_project_info`** — no `website_url` **and** no `twitter_url`/`telegram_url` **and** no pair `info` description. `MEASURED` / `SEVERITY_MEDIUM` for a token above a config MC (a real $20M+ project has *some* web presence).
- **`market_structure.thin_participation`** — `market_cap / (txns_h24 or unique-buyer proxy)` far above a reference, i.e. a huge cap sustained by a handful of trades. `MEASURED` / graded severity.
- **`market_structure.low_turnover_flat`** — `volume_h24 / liquidity_usd` **below** a floor **and** `|price_change_h24|` below a flatness threshold **and** `txns_h24` below a floor → a dead/parked token. `MEASURED` / `SEVERITY_MEDIUM`.

Weighting: add a small `identity` dimension weight (or fold identity into `market_structure` and raise its 0.08 weight); keep the existing hard-override mechanism so `famous_name_mismatch` alone forces at least `HIGH`. **No migration** (`risk_signals` is key-agnostic; only `config/risk.php` + the evaluator + `RiskScoreCalculator` weights change). Update `RiskSignal::GROUP_*`, `docs/risk-screening.md`, the detail-page group labels in `frontend/src/lib/risk.ts`, and add `RiskScreeningTest` cases (including an explicit DOGE-on-Solana fixture that must land in RISK WATCH, not MAIN).

**Why this is next**
- **Priority rule:** it is a **correctness** failure (priority #1) of the primary user-facing output — the MAIN LIST currently *can* admit a ticker-squat — and it is **risk filtering** (priority #4), which the rule ranks above historical completeness and intelligence.
- The product owner **explicitly** singled this out: *"If current implementation still allows it: mark it as a HIGH PRIORITY GAP."* The audit confirms it does.

**What it depends on**
- Nothing new. `RiskSignalEvaluator`, `RiskScoreCalculator`, `TokenRiskContext`, `MainListDecision`, `RiskSnapshotRecorder`, `config/risk.php` all already exist. The only new inputs are a small curated famous-asset registry (config array) and the token's already-stored `name`/`symbol`/`website_url`/`twitter_url`/`telegram_url` + latest snapshot fields — all in PostgreSQL. No new provider, no new table.

**What it unlocks**
- Closes open requirements **G** and the flat/low-turnover/missing-info parts of **H** and **I** in one deterministic pass.
- Makes the MAIN LIST trustworthy as "the filtered investable-research universe" — the stated purpose.
- Every downstream feature that reads the MAIN LIST (trending `main_list_eligible` badge, detail page) immediately benefits.
- After this, Phase 2 (`HISTORICAL_VERIFIED` reliability) becomes the clear #2, followed by the operational `ANTHROPIC_API_KEY` unblock for Phase 5.

*(Not chosen, and why: `HISTORICAL_VERIFIED` reliability is data integrity but is likely an env/key fix pending diagnosis; monthly-champion data is priority #5; the AI unblock is a config action, not a code step; UI/doc cleanup is priority #7.)*

---

## 15. Verification Baseline

Captured 2026-09-01 at HEAD `d7b8cd6`, no changes made:

| Check | Command | Result |
|---|---|---|
| Backend tests | `docker compose exec backend php artisan test` | **457 passed** (1977 assertions), 0 failed, 32 test classes, ~58s |
| Backend style | `docker compose exec backend ./vendor/bin/pint --test` | **PASS — 257 files**, 0 issues |
| Frontend lint | `docker compose exec frontend npm run lint` (oxlint) | **0 warnings, 0 errors** — 27 files |
| Frontend build | `docker compose exec frontend npm run build` (`tsc -b && vite build`) | **✓ built** — `index.js` 302 kB / `index.css` 24.7 kB |
| Docker compose | `docker compose config -q` | **valid** |
| Docker services | `docker compose ps` | `postgres` healthy, `backend` healthy, `frontend` up, `scheduler` up — 4/4 |
| Migrations | `SELECT migration FROM migrations` | **27 applied** (24 app + 3 Laravel), none pending |
| Scheduler | `schedule:list` | **9 commands** registered (see §6) |
| API routes | `route:list --path=api` | **12 routes** (see §5) |

**Red / stale tests:** none failing. `tests/Unit/Trend/TrendScorerTest.php` is **stale** (tests the dead `App\Services\Trend\TrendScorer` — passes but covers nothing in production; delete with §10 #1).

**Live data-layer red flags** (not caught by tests — the reason §12 Phase 7 recommends a health harness):
- `historical_peak_evidences`: 0 `HISTORICAL_VERIFIED` / 125 `UNKNOWN`.
- `pump_explanations`: 13/13 `failed`.
- `token_narrative_reports`: 16/16 `failed`.
- `monthly_rankings`: 0 rows.
- `evidences`: 0 news (GDELT unreachable in dev — expected).

---

## 16. Git State

```
$ git status
On branch main
Your branch is up to date with 'origin/main'.
nothing to commit, working tree clean          # BEFORE this document was written

$ git log --oneline -10
d7b8cd6 feat: near-real-time trending tracking (top trending memecoins)
0d052b6 feat: add historical monthly champion backfill
3cf1c09 feat: rework monthly champions into per-chain-bucket ranking
afa28d9 feat: add deterministic memecoin risk & safety screening
8a01ea9 docs: add memecoin risk & safety screening reconnaissance
73d61ef feat: token narrative intelligence (origin + popularity)
6b27f42 feat: add "Recently Crossed $5M" qualification events
a69a28b feat: trending-meta-first memecoin discovery + $5M–$200M peak band
2bf4fac feat: pump intelligence, historical qualification, live chart + trending recon
bc943d4 feat: add token detail view + Git & Sprint Commit Policy
```

**Change classification:**
- **Committed & pushed:** everything through `d7b8cd6`. `origin/main` == local `main`.
- **Staged:** nothing.
- **Unstaged (before this file):** nothing — working tree was clean.
- **After this audit:** the only change is the creation of **`docs/current-system-and-roadmap.md`** (this file), currently **unstaged and uncommitted** per instructions.
- **Gitignored / untracked, intentional:** `backend/storage/app/monthly-champion-candidates.json` (operator research seed — `backend/storage/app/.gitignore` = `*`).

---

## Final confirmation

- **No code changes.** No source, migration, config, frontend, test, scheduler, or database modification was made during this audit.
- **No commit. No push.** The working tree contains exactly one new untracked file: `docs/current-system-and-roadmap.md`.
- All inspection was read-only (`git`, `psql SELECT`/`\d`, `artisan test`/`route:list`/`schedule:list`, `docker compose ps`/`config -q`, `curl` against running read APIs, and reading source files).
