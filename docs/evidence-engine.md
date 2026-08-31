# Evidence Engine (Step 16B)

Collects timestamped **facts** present around a detected
[`PumpEvent`](sprint-1-discovery.md#pump-event-detection-step-16a). Answers
*"what evidence was present around the time this pump happened?"* — **not**
*"why did it pump?"* (that is Step 16C, and it will still only ever summarise
these stored facts).

Backend only. No AI, no LLM, no frontend changes.

---

## 1. What "evidence" is here

One `evidences` row = **one timestamped fact**, attributed to a source, with a
neutral one-sentence summary. Examples of facts we store:

- "Observed market cap increased 3.9× between the event start and peak over 30
  minutes." (`MARKET`)
- "Stored DexScreener metadata lists a linked website for this token
  (ponsfees.com)." (`ORIGIN`)
- "Tracked token *Utility Coin* (UTILITY, robinhood) rose 92% during the 60
  minutes before this event started." (`RELATED_TOKEN`)
- "Crypto-news article '…' (coindesk.com) was published 12 minutes before the
  observed pump peak." (`NEWS`)

We do **not** store: interpretations, conclusions, ranked causes, "the catalyst
was …", or provider JSON payloads.

### Schema (`evidences`)

| column | meaning |
|---|---|
| `pump_event_id`, `token_id` | the event this fact belongs to, and its token (both FK, cascade delete) |
| `category` | `MARKET` \| `TOKEN_METADATA` \| `ORIGIN` \| `NEWS` \| `RELATED_TOKEN` \| `LISTING` \| `COMMUNITY` |
| `source` | `internal` \| `dexscreener` \| `gdelt` \| … |
| `source_url`, `title` | for external items |
| `observed_at` | when *we* observed the fact |
| `published_at` | when the external item was published (news) |
| `relevance_score` | deterministic 0–100 — *how relevant to investigating*, **not** a probability of causation |
| `confidence` | `low` \| `medium` \| `high` |
| `summary` | neutral one-sentence fact |
| `raw_reference` | short id / domain / hash — **never** the full payload |
| `dedupe_hash` | `sha1(category\|source\|source_url\|title\|published_at)` |
| `collected_at` | when the last run wrote/refreshed the row |

`unique(pump_event_id, dedupe_hash)` — the idempotency key.
`PumpEvent hasMany Evidence`; `Evidence belongsTo PumpEvent` + `belongsTo Token`.
Duplicate evidence across *different* events is acceptable (MVP — no
many-to-many).

`LISTING` and `COMMUNITY` are defined for future collectors; **no rows are
written with them yet**.

---

## 2. Evidence vs causality

**Evidence is stored separately from interpretation.** Temporal correlation is
never causation.

- ✅ "ANSEM gained 84% approximately 20 minutes before the MANLET pump."
- ❌ "ANSEM caused MANLET to pump."

Every collector's summary uses neutral phrasing. `RELATED_TOKEN` and `NEWS`
summaries explicitly append that the record is *a temporal observation only —
not a causal claim*. `relevance_score` measures investigative usefulness, not
probability of causation. Nothing in this engine ever selects a "main cause".

---

## 3. Investigation window

Every collector is bounded to one window per event:

```
investigation_start = pump_event.started_at − EVIDENCE_WINDOW_BEFORE_MINUTES (60)
investigation_end   = pump_event.peak_at    + EVIDENCE_WINDOW_AFTER_MINUTES  (30)
```

Collectors must never read outside it. `EvidenceWindow::for($event)` builds it;
`EvidenceWindow::relativeToPeak($at)` produces the neutral timing phrase
("*N minutes before the observed pump peak*").

The `RELATED_TOKEN` collector additionally uses a **lead window**
(`started_at − EVIDENCE_RELATED_LEAD_WINDOW_MINUTES` → `started_at`).

---

## 4. Collectors

`app/Services/Evidence/` — orchestrated by `EvidenceCollectionService`, persisted
by `EvidenceRecorder`. Each implements `EvidenceCollector`
(`collect() : list<EvidenceCandidate>`, `name()`, `isExternal()`). A collector
that throws is logged, counted as a provider failure, and skipped — the run
continues.

| Collector | External? | Source data | Produces |
|---|---|---|---|
| `MarketEvidenceCollector` | no | `pump_events` metrics + the token's `market_snapshots` inside the window | market-cap move, price move, rolling-24h volume/txn change, liquidity & order-flow context |
| `TokenMetadataEvidenceCollector` | no | already-stored `tokens.{website_url,twitter_url,telegram_url}` + `earliest_pair_created_at` | linked project resources (or their absence); earliest observed DEX-pool age relative to the pump |
| `RelatedTokenEvidenceCollector` | no | other tracked tokens' `market_snapshots` in the lead window | other tracked tokens that rose ≥ threshold shortly before this event |
| `NewsEvidenceCollector` | **yes** | GDELT 2.1 DOC API | crypto/news articles published inside the window whose title names this token |

`LISTING` / `COMMUNITY` collectors are **not implemented**.

### Metadata note

DexScreener does **not** expose a token description anywhere (verified against
`/token-pairs/v1` pair `info` and `/token-profiles/latest/v1`). Step 16B added
only the smallest necessary columns —
`tokens.{website_url,twitter_url,telegram_url,image_url,metadata_updated_at}` —
populated from the pair `info` object during normal discovery. The metadata
collector therefore says *"Project metadata lists a linked website"*, never
*"the token was created to …"*.

---

## 5. News provider — GDELT

`GdeltNewsClient` → GDELT 2.1 DOC API (`mode=ArtList&format=json`). Free, no API
key, server-side only.

- **Conservative query** from the exact token **name** (quoted phrase +
  `(crypto OR token OR memecoin OR coin)`), or the **symbol** if the name is
  generic/short and the symbol is ≥ `EVIDENCE_NEWS_MIN_SYMBOL_LENGTH` (4) and not
  itself generic. Generic terms (`meme`, `coin`, `inu`, `pepe`, `doge`, …) never
  form a query. If neither is usable, the collector produces nothing.
- **Window** = the event investigation window (`startdatetime` / `enddatetime`).
- **Bounded**: `EVIDENCE_NEWS_MAX_RESULTS_PER_EVENT` (10) per event;
  `EVIDENCE_NEWS_MAX_REQUESTS_PER_RUN` (15) shared across the whole command run.
- **Resilient**: any timeout / non-200 / non-JSON → empty list, one concise
  `Log::warning`, `providerFailures++`, other collectors unaffected, command
  still exits 0. **No fabricated evidence.**
- **Timing is a fact, not a cause**: the summary reads *"published 12 minutes
  before the observed pump peak"*, never *"triggered the pump"*.
- Not paid search; not scraped search-engine HTML. Set
  `EVIDENCE_NEWS_PROVIDER=none` (or `EVIDENCE_NEWS_ENABLED=false`) to disable.

> GDELT only indexes news ~15 min+ after publication, so very fresh events may
> legitimately return nothing.

---

## 6. Related-token evidence

PostgreSQL only — **no internet, not the future `TokenRelation` graph.**

For each event: look at every *other* tracked token (same chain only unless
`EVIDENCE_RELATED_CROSS_CHAIN=true`), find its strongest low→high move
(market cap, price fallback) in the lead window before `started_at`. If that
move ≥ `EVIDENCE_RELATED_MIN_MOVE_PCT` (40%), record it. Ranked by move size,
capped at `EVIDENCE_RELATED_MAX` (5).

- **Confidence** — `medium` when the move ≥ 2× threshold **and** the peer's rise
  peaked within 25 min of this event's start; otherwise `low`. **Never `high`.**
- Name/symbol overlap (excluding common words) adds a small relevance nudge and a
  note — **never** a confidence bump.
- Summary: *"Tracked token X rose 84% during the 60 minutes before this event
  started … This is a temporal observation only — it does not indicate
  causation."*

---

## 7. Relevance scoring (deterministic, 0–100)

No randomness, no wall-clock dependence. Means **"how relevant to
investigating this event"** — *not* "probability this caused the event".

Inputs, by collector:

- **Market** — fixed per fact type (100 market-cap move, 95 price, 72 rolling
  activity, 60 liquidity/flow).
- **Related token** — base + move size (capped) + temporal proximity to the
  event start (closer = higher) + small name-overlap nudge.
- **News** — base + title match strength (exact name > symbol) + trusted-domain
  nudge + temporal proximity to the peak.
- **Metadata** — fixed (45 website, 38 socials, 50 pool-age, 20 "no links").

Always clamped to `[0, 100]` by `EvidenceCandidate::toAttributes()`.

---

## 8. Confidence model

| | Meaning |
|---|---|
| **high** | timestamped direct evidence, reputable source, direct token match, strong temporal proximity. In practice: internal market facts; a news article whose *title* names the token, from a trusted domain, within 60 min of the peak. |
| **medium** | indirect but clearly relevant — strong timing with a weaker source/entity match, or a solid but non-headline signal. |
| **low** | weak context, generic narrative, uncertain entity match, or a small move. |

`RELATED_TOKEN` is capped at `medium`.

---

## 9. Entity matching (ticker-collision guard)

The same symbol can belong to many unrelated tokens, so **matching is never on
symbol alone**:

- **News** — an article is kept only if its **title** contains the exact token
  name (word-boundary) or the symbol as a standalone token. Symbol-only matches
  can never reach `high`. Everything else is dropped.
- **Related token** — matched on *timing*, not identity; a shared symbol only
  adds a note. A same-symbol token on another chain is excluded by default.

---

## 10. Cooldown & idempotency

The command is safe to run every few minutes.

- `pump_events.evidence_collected_at` is stamped whenever an event is
  investigated (even if zero evidence was found — so empty events don't re-hit
  the news API every tick).
- A run skips events investigated within `EVIDENCE_COLLECTION_COOLDOWN_HOURS`
  (2). `--force` ignores the cooldown.
- Only events peaking within `EVIDENCE_RECENT_EVENT_HOURS` (48) are considered;
  at most `EVIDENCE_MAX_EVENTS_PER_RUN` (20) per run.
- `EvidenceRecorder` upserts on `(pump_event_id, dedupe_hash)` — a re-run
  refreshes rows, never duplicates them. Historical evidence stays persisted.

### Command & schedule

```bash
docker compose exec backend php artisan memecoins:collect-evidence [--force]
```

```
Evidence collection completed.

Pump events analyzed:       10
Events skipped (cooldown):  0
Events with new evidence:   10
News evidence:             0
Market evidence:           40
Related-token evidence:    17
Origin evidence:           18
Token-metadata evidence:   10
New evidence records:       82
Total evidence records:     85
Provider failures:          10
Duration (s):               33.82
```

Scheduled (`routes/console.php`, reusing the existing `scheduler` container) on
`8,18,28,38,48,58 * * * *` — same cadence as discovery, offset a few minutes
**after** `memecoins:detect-pumps` so every new event is investigated the same
hour. `withoutOverlapping(15)`. No second scheduler container, no queue/Redis.

```
discovery → snapshots   :00 :10 :20 …
pump detection          :05 :15 :25 …
evidence collection     :08 :18 :28 …
```

---

## 11. Limitations

- **Not a "why".** This engine records facts. It never explains, ranks causes,
  or names a catalyst. Step 16C will *summarise* these stored facts and nothing
  else.
- **Observed, coarse timing.** Everything is anchored to ~10-minute snapshot
  `observed_at` values, not tick data.
- **GDELT reachability.** In network-restricted environments GDELT may be
  unreachable; the engine then records zero news evidence and logs the failure —
  it does not fabricate. (Observed in the current dev environment: all GDELT
  calls time out; the other three collectors are unaffected.)
- **GDELT latency & coverage.** ~15-minute indexing lag; non-English / niche
  outlets under-represented; a genuine catalyst tweet is not "news".
- **Related-token evidence is temporal only.** Co-movement of newly-listed
  tokens (shared launchpad, same-day listings) is common and does not imply a
  relationship. No narrative graph, no shared-holder analysis.
- **Metadata is thin.** Only links + pool age — no description (DexScreener does
  not expose one), no on-chain deployer / mint-authority analysis.
- **No exchange listings, social sentiment, influencer/wallet detection, or
  monthly rankings** — out of scope for Step 16B.
- **Entity matching is heuristic.** Title-based name matching can still miss a
  legitimately relevant article or (rarely) admit a same-name unrelated project.
- Within a single run, two peer tokens sharing an identical symbol collapse to
  one `RELATED_TOKEN` row (same `dedupe_hash`); acceptable for MVP.

---

## 12. Next layer — AI interpretation (Step 16C)

The evidence records this engine produces are the **only** input to the AI pump
explanation layer. `memecoins:explain-pumps` sends one `PumpEvent` plus its
ranked, capped evidence to the configured LLM provider, which returns a
structured, evidence-grounded interpretation — every claim citing evidence ids,
no causal language, `UNKNOWN` when the evidence is thin. The model is an
**interpreter of these facts, never a source of new ones**. See
[docs/pump-explanation.md](pump-explanation.md).

```
Evidence (this engine) → AI Explanation (Step 16C) → detail API `pump_intelligence`
```
