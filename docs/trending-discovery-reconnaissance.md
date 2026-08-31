# Trending Discovery Reconnaissance

**Status:** research / reconnaissance only. **No production code, no migrations,
no DB, no scheduler, no React, no historical/pump/evidence/AI changes, no new
providers.** Nothing here is implemented.
**Date performed:** 2026-08-31
**Investigator method:** live `curl` against `api.dexscreener.com` /
`io.dexscreener.com`; headless-Chrome page loads of `dexscreener.com` with full
network-log capture; the official docs at `https://docs.dexscreener.com/api/reference`.
Raw payloads are summarised, not pasted.

Base docs: [dexscreener-reconnaissance.md](dexscreener-reconnaissance.md) ·
[historical-peak-reconnaissance.md](historical-peak-reconnaissance.md) ·
[sprint-1-discovery.md](sprint-1-discovery.md).

---

## 1. Product Requirement

The main memecoin list should be driven by DexScreener's **trending** universe
(like the DexScreener UI's *Trending — 5M / 1H / 6H / 24H*, per chain), **not**
primarily by broad keyword search.

> "Take trending coins across every supported chain, then filter them."

**Main-list filter (all must hold):**

| Gate | Value |
|---|---|
| verified / observed **market cap** | `$5,000,000 ≤ market_cap ≤ $200,000,000` |
| age (earliest DEX pool) | `≤ 30 days` |
| 24h volume | `> 0` |
| liquidity (where available) | `> 0` |

**Never** use FDV as market cap. **Never** let `HISTORICAL_ESTIMATE` (FDV basis)
qualify the main list — that rule from the prior correction still holds.

---

## 2. DexScreener Trending UI — how the website actually works

### 2.1 What the page loads (observed)

Headless-Chrome network capture of `https://dexscreener.com/` and
`/solana`, `/base`, `/ethereum`:

| Host | Role | Transport | Observed URLs |
|---|---|---|---|
| `api.dexscreener.com` | **Documented public REST API** | HTTPS GET | the endpoints in [dexscreener-reconnaissance.md §1](dexscreener-reconnaissance.md) |
| `io.dexscreener.com` | **Internal real-time feed** — powers the Trending table | **WebSocket** (+ a little REST) | `wss://io.dexscreener.com/dex/screener/v7/pairs/h24/1?rankBy[key]=trendingScoreH6&rankBy[order]=desc` ; `https://io.dexscreener.com/metas/v1/trending?chainId=solana` |
| `cfw.dexscreener.com` | Paid native **ad** slot | HTTPS GET | `.../a/direct/screener/native/rainbet`, `.../native/metawin` (rotates) |
| `pls.dexscreener.com` | Self-hosted **Plausible** analytics | HTTPS | `.../api/event`, `plausible…outbound-links.js` — not data |
| `cdn.dexscreener.com` | Static assets / token images | HTTPS | not data |

**The Trending *table* is fed by a WebSocket to `io.dexscreener.com`, not by the
documented REST API.**

### 2.2 The trending table URL (observed, verbatim)

Homepage default:

```
wss://io.dexscreener.com/dex/screener/v7/pairs/h24/1?rankBy[key]=trendingScoreH6&rankBy[order]=desc
```

Per chain (from `/solana`):

```
wss://io.dexscreener.com/dex/screener/v7/pairs/h24/1?rankBy[key]=trendingScoreH6&rankBy[order]=desc&filters[chainIds][0]=solana
```

Timeframe change (loaded `/base?rankBy=trendingScoreH1&order=desc`):

```
wss://io.dexscreener.com/dex/screener/v7/pairs/h24/1?rankBy[key]=trendingScoreH1&rankBy[order]=desc&filters[chainIds][0]=base
```

- `v7` — the internal API is **versioned and changes** (community projects
  reference older `v3`/`v5`).
- `rankBy[key]` ∈ `trendingScoreM5 | trendingScoreH1 | trendingScoreH6 | trendingScoreH24`
  — all four appear in the rendered page markup and map 1:1 to the UI tabs
  *5M / 1H / 6H / 24H*.
- `filters[chainIds][n]` — repeatable; the per-chain filter.
- `trendingScore*` is a **proprietary DexScreener momentum score**. Its formula
  is not published and its value is **not** available from the documented API.

### 2.3 The frame format

Community reverse-engineering projects
(`itsdarkerinnit/dexscraper`, `doffn/Dexscreen-scraper`,
`muhammetakkurtt/dexscreener-realtime-client`) all report the WebSocket sends a
**custom binary / length-prefixed frame**, not JSON. It has to be hand-decoded
and the decoder breaks whenever DexScreener bumps the version or reshapes the
frame.

### 2.4 The bot wall (the decisive constraint)

`io.dexscreener.com` sits behind **Cloudflare Bot Management**. Every non-browser
request I made returned:

```
HTTP/2 403
server: cloudflare
set-cookie: __cf_bm=…            (Cloudflare bot-management token, ~30 min TTL)
<title>Attention Required! | Cloudflare</title>
```

Tested and **all 403**:

- plain `curl` HTTPS GET on `/dex/screener/v7/pairs/...`
- a raw WebSocket `Upgrade` handshake (`Connection: Upgrade`, `Sec-WebSocket-*`, `Origin: https://dexscreener.com`)
- `https://io.dexscreener.com/metas/v1/trending?chainId=solana`

A **real headless browser** (Chrome, realistic UA) *did* pass Cloudflare and open
the WebSocket — because it solves the JS/fingerprint challenge and carries the
`__cf_bm` cookie. So the feed is reachable **only** through browser automation or
a Cloudflare-bypass service.

> `api.dexscreener.com` is **not** behind this wall — plain `curl` works today
> and the current pipeline already uses it.

---

## 3. Official API Support

Verified against `https://docs.dexscreener.com/api/reference` (2026-08-31) and
live probing.

### 3.1 Every documented endpoint

| Endpoint | Method | Rate limit | Returns |
|---|---|---|---|
| `/token-profiles/latest/v1` | GET | 60/min | newest paid token profiles (`chainId`+`tokenAddress`+links) |
| `/token-profiles/recent-updates/v1` | GET | 60/min | recently-edited profiles |
| `/community-takeovers/latest/v1` | GET | 60/min | community-takeover flags |
| `/ads/latest/v1` | GET | 60/min | ad inventory |
| `/token-boosts/latest/v1` | GET | 60/min | newest paid "boosts" |
| `/token-boosts/top/v1` | GET | 60/min | largest cumulative boosts |
| `/orders/v1/{chainId}/{tokenAddress}` | GET | 60/min | paid-order status |
| `/latest/dex/pairs/{chainId}/{pairId}` | GET | 300/min | one pair, full market object |
| `/latest/dex/search?q=` | GET | 300/min | ≤ 30 full pair objects for a free-text query; **no chain filter, no pagination** |
| `/token-pairs/v1/{chainId}/{tokenAddress}` | GET | 300/min | all pairs for one token (≤ 30), full market objects |
| `/tokens/v1/{chainId}/{tokenAddresses}` | GET | 300/min | pair objects for a comma-list of tokens on one chain |
| **`/metas/trending/v1`** | GET | 60/min | **trending narrative "metas"** — aggregates, *not* individual tokens |
| **`/metas/meta/v1/{slug}`** | GET | 60/min | one meta **+ its member pairs** (full pair objects) |

### 3.2 Answers to the 10 questions

1. **Official documented REST endpoint for Trending (pairs/tokens)?**
   **No.** The only thing named "trending" is `/metas/trending/v1`, and it
   returns **narratives**, not a ranked token/pair list. There is no documented
   `trendingScore`, no `rankBy`, no screener feed.

2. **Public endpoint returning `chainId / tokenAddress / pairAddress / marketCap
   / liquidity / volume / priceChange / trending rank`?**
   **Partially.** `/metas/meta/v1/{slug}` returns full pair objects with
   `chainId, pairAddress, baseToken.address, priceUsd, txns{m5,h1,h6,h24},
   volume{m5,h1,h6,h24}, priceChange{…}, liquidity{usd,base,quote}, fdv,
   marketCap, pairCreatedAt, info{…}, labels`. It does **not** carry a
   **trending rank** or DexScreener's `trendingScore`. `/latest/dex/search`
   returns the same fields but is keyword-gated. The *rank* only exists on the
   undocumented WS feed.

3. **Trending per chain?** Only on the WS feed (`filters[chainIds][n]`). The
   documented `/metas/*` endpoints are **chain-agnostic** (a meta spans all
   chains); `io.dexscreener.com/metas/v1/trending?chainId=` adds a chain filter
   but is Cloudflare-gated.

4. **Timeframe 5m / 1h / 6h / 24h?** On the WS feed only, via
   `rankBy[key]=trendingScoreM5|H1|H6|H24`. The documented `/metas/*` endpoints
   expose `marketCapChange.{m5,h1,h6,h24}` per meta and `priceChange`/`volume`
   per timeframe per pair — enough to compute *your own* timeframe ranking, but
   not DexScreener's.

5. **The exact ranking behind "Trending 6H"?** `rankBy[key]=trendingScoreH6` on
   the WS. The score itself (its inputs/weights) is **not public**.

6. **Top N trending pairs?** Only from the WS feed (the frame is an ordered
   list). Not from the documented API.

7. **Obtainable without paid API / API key / HTML scraping / browser
   automation?**
   - Documented REST (`api.dexscreener.com`): **yes** — no key, free, works from
     plain `curl`.
   - The real trending feed (`io.dexscreener.com`): **no** — needs a browser
     (Cloudflare) and a binary decoder. Any "clean" access is via a paid
     third-party wrapper.

8. **Official WebSocket endpoints?** The website uses
   `wss://io.dexscreener.com/dex/screener/v7/…`. It exists and works (for
   browsers).

9. **Are those WebSockets documented/supported by DexScreener?** **No.** They
   appear **nowhere** in `docs.dexscreener.com`. No version guarantee, no SLA, no
   acknowledgement. Third-party blogs calling it a "public WebSocket API" are not
   DexScreener sources.

10. **What the website uses internally:** the documented REST API for
    detail/search pages **plus** the undocumented `io.dexscreener.com` WebSocket
    (`v7`, binary frames, Cloudflare-gated) for the live Trending / screener
    tables, **plus** `/metas/v1/trending` for the narrative bar, **plus**
    `cfw.dexscreener.com` for the one labelled ad.

### 3.3 Source classification

| Mechanism | Classification | Free? | Usable in production? |
|---|---|---|---|
| `api.dexscreener.com/*` (all documented endpoints) | **OFFICIAL_DOCUMENTED_API** | yes | **yes** |
| `/metas/trending/v1` + `/metas/meta/v1/{slug}` | **OFFICIAL_DOCUMENTED_API** | yes | **yes** — best trending-adjacent official path |
| `wss://io.dexscreener.com/dex/screener/v7/…` (`trendingScore*`) | **UNDOCUMENTED_INTERNAL_ENDPOINT** | "free" but Cloudflare-gated + binary | **no** (needs browser automation; brittle; against ToS spirit) |
| `io.dexscreener.com/metas/v1/trending` | **UNDOCUMENTED_INTERNAL_ENDPOINT** | Cloudflare-gated | **no** |
| headless-browser holding the WS open | **OFFICIAL_WEBSITE_DATA** via **SCRAPING/automation** | infra cost | **not recommended** |
| Apify "DexScreener Realtime/Trending" actors, RapidAPI wrappers | **UNOFFICIAL_THIRD_PARTY** | **paid** | out of scope (task forbids new providers) |
| parsing `dexscreener.com` HTML | **SCRAPING** | — | forbidden by task |

---

## 4. Internal / WebSocket Data — detail

- **URL:** `wss://io.dexscreener.com/dex/screener/v7/pairs/{tf}/{page}?rankBy[key]=…&rankBy[order]=desc&filters[chainIds][n]=…`
  (`{tf}` observed as `h24`, `{page}` as `1`).
- **Auth:** none in the URL, but Cloudflare requires a browser-issued `__cf_bm`
  cookie + a passed JS challenge. TLS-fingerprint / header-order checks are also
  typical of CF Bot Management.
- **Payload:** binary, undocumented, version-tagged (`v5` → `v7` seen in the
  wild). Community decoders exist but are maintenance liabilities.
- **Stability risk:** DexScreener can (and does) change the version, the frame
  layout, the score, or tighten Cloudflare at any time, with **zero notice** —
  it is their private site infrastructure, not a product.
- **Legal / ToS:** DexScreener publishes a documented API precisely so people
  *don't* hit `io.dexscreener.com`. Building on it invites blocking.

**Verdict for §4:** the real trending feed is **not a viable production
dependency** for this project under the stated constraints (free, no key, no
scraping, no browser automation, maintainable).

---

## 5. Per-Chain Discovery

### 5.1 Can trending be requested per chain?

- **WS feed:** yes — `filters[chainIds][0]=solana` (repeatable). Not usable
  (§4).
- **Documented `/metas/*`:** **no** — metas are cross-chain. You get all chains
  mixed and filter `chainId` yourself afterward (same pattern the current
  pipeline already uses for `/latest/dex/search`).

### 5.2 The chain list

- DexScreener's nav lists **~73 chains** (rendered into the homepage markup:
  `abstract, algorand, apechain, aptos, arbitrum, avalanche, base, beam,
  berachain, blast, bsc, cardano, celo, conflux, cronos, ethereum, fantom,
  flare, … , solana, sonic, story, sui, taiko, telos, ton, tron, unichain, …`).
- **There is no official `GET /chains` endpoint** (`/chains`, `/latest/dex/chains`,
  `/v1/chains` all 404).
- The list is only obtainable by **scraping the SPA markup** — forbidden and not
  a stable contract.
- **Recommendation:** do **not** hard-code "every chain". Keep the existing
  approach — `chain_id` is a free-text slug on every pair; **discover chains from
  the candidates actually seen** (the pipeline already records
  `chains_discovered`) and keep a curated **allow-list** of chains we choose to
  surface (`solana, ethereum, bsc, base, arbitrum, polygon, avalanche, …`),
  editable in config.

### 5.3 What the trending-metas sweep actually covers (live, 2026-08-31)

`GET /metas/trending/v1` → 18 metas → `GET /metas/meta/v1/{slug}` ×18:

- **392 unique tokens**, chains: `solana 256, bsc 56, ethereum 38, base 29,
  robinhood 7, ton 3, avalanche 2, ink 1` — **8 chains**.
- Better `ethereum`/`bsc`/`base` representation and far less `robinhood` noise
  than the current keyword sweep (which is `robinhood`/`solana`-heavy — see §9).

---

## 6. Topbar vs Main Trending Table

The user asked specifically about the DexScreener "top trending bar".

**Observed:** the horizontal strip below the header is the **Metas bar** — a
scrolling row of narrative chips, each `<emoji> <Name> $<aggregate marketCap>`:

```
🐈 Cat $663M   🐻‍❄️ Character $2.54B   🇨🇳 Chinese $712M   🐶 Dog $1.11B
🤖 AI $2.12B   🐾 Internet Animals $109M   🏆 Meme Hall of Fame $4.61B
😈 Degen $842M   🍊 Trump $6.16B   🎨 Knockoff Legends $4.8M   🤙 Slang $42M
🧠 Brainrot $11.6M   🌟 Celebrity $682M   👨‍🚀 Elon Musk $71.6M   🎬 TikTok $18M
🖼️ NFT $890M   📈 Stonks $512M   💸 x402 $142M
[ Rainbet — $1.5M in Monthly Rewards … Play now!  · Ad ]
```

Answers:

- **What it represents:** the **trending narratives** feed
  (`io.dexscreener.com/metas/v1/trending` on the site; **equivalently the
  documented `GET /metas/trending/v1`**). It is **A + a bit of C**:
  - **A — same underlying data as the documented metas endpoint.** The 18 chips,
    in the exact same order, match `GET /metas/trending/v1` verbatim. It is **not
    the same ranking as the main Trending table** (that table ranks *individual
    pairs* by `trendingScoreH6`; the bar ranks *narratives*).
  - **C — exactly one, clearly-labelled `Ad`** is appended at the end
    (`cfw.dexscreener.com/a/direct/screener/native/<sponsor>`, rotates
    rainbet / metawin / …). The ad is **not** part of the ranking and is
    labelled "Ad".
- **Is the ranking market-driven?** The *narrative* ordering is DexScreener's
  (undisclosed, presumably volume/momentum-weighted); it is **not** alphabetical
  or by marketCap. The single Ad is paid.
- **Do boosted/paid tokens influence it?** The narrative bar itself: no evidence
  of paid placement inside the chips. The trailing slot: yes, it's a paid ad,
  but it's separated and labelled.
- **Reproducible programmatically?** **The narrative bar: yes**, via
  `GET /metas/trending/v1` (order preserved). **The main Trending table: no**
  (WS only). Skip the Ad.

---

## 7. Timeframes (5m / 1h / 6h / 24h)

| Want | Documented API | Undocumented WS |
|---|---|---|
| DexScreener's `trendingScore` for M5 / H1 / H6 / H24 | ❌ not available | ✅ `rankBy[key]=trendingScoreM5\|H1\|H6\|H24` |
| Per-**meta** change over m5 / h1 / h6 / h24 | ✅ `/metas/trending/v1` → `marketCapChange.{m5,h1,h6,h24}` | ✅ |
| Per-**pair** `priceChange` + `volume` per m5 / h1 / h6 / h24 | ✅ every pair object (`/metas/meta/v1/{slug}`, `/latest/dex/search`, `/token-pairs/v1`) | ✅ |
| Per-pair `txns.{m5,h1,h6,h24}.{buys,sells}` | ✅ | ✅ |

**So:** we can reproduce DexScreener's *narrative* timeframe ranking exactly, and
we can build our **own** per-token timeframe "heat" score from
`priceChange` + `volume` + `txns` at m5/h1/h6/h24 — we just can't get
DexScreener's exact proprietary number.

---

## 8. Market-Cap / Volume Filtering — where the $5M–$200M gate goes

### 8.1 Does the trending source carry enough data?

**Yes.** `/metas/meta/v1/{slug}` pair objects already include `marketCap`, `fdv`,
`volume.h24`, `liquidity.usd`, `pairCreatedAt` (present on ~80–100% of pairs,
same "PARTIALLY_AVAILABLE" behaviour as elsewhere). No enrichment call is needed
just to *pre-filter*.

### 8.2 Recommended pipeline (filter **before** enrichment)

```
1. GET /metas/trending/v1                         → 18 trending meta slugs   (1 call, 60/min bucket)
2. GET /metas/meta/v1/{slug}  ×18                 → ~400 pair objects w/ market data  (18 calls, 60/min)
3. Union + dedupe on (chainId, lower(tokenAddress))       [keep highest-marketCap pair per token]
4. CHEAP PRE-FILTER on the meta-pair data (no extra calls):
     a. chain_id ∈ allow-list
     b. marketCap present  (skip if null — never use fdv)
     c. 5_000_000 ≤ marketCap ≤ 200_000_000
     d. volume.h24 > 0
     e. liquidity.usd > 0        (skip gate only if liquidity absent AND policy says "allow")
     f. this pair's pairCreatedAt ≤ ~35 days  (loose pre-gate — one pair, not the earliest)
   → ~400 → ~50–70 survivors
5. ENRICH survivors: GET /token-pairs/v1/{chainId}/{tokenAddress}   (≤ ~70 calls, 300/min bucket)
     → all pairs → earliest_pair_created_at = min(pairCreatedAt), primary pair = max liquidity.usd
6. STRICT FILTER:
     a. earliest_pair_created_at present AND age ≤ 30 days   (exclude, don't guess, if null)
     b. primary-pair marketCap present AND 5M ≤ marketCap ≤ 200M
     c. volume.h24 > 0, liquidity.usd > 0
7. Hand to the EXISTING pipeline unchanged:
     PERSIST Token + MarketSnapshot → UPDATE observed_peak → CURRENT_OBSERVATION check
     → HISTORICAL LOOKUP → QUALIFICATION (CURRENT_OBSERVATION | HISTORICAL_VERIFIED only;
       HISTORICAL_ESTIMATE never qualifies) → PERSIST EVIDENCE
```

**Filter before enrichment.** Enrichment is 1 call/token; pre-filtering on the
free meta data collapses ~400 candidates to ~50–70 *before* spending any
`/token-pairs/v1` calls. Filtering after enrichment would waste ~330 calls/run.

### 8.3 Where the $5M–$200M band applies (decision point to confirm)

The band applies to a **verified / observed market cap**, never FDV, and
`HISTORICAL_ESTIMATE` never qualifies (unchanged rule). Two readings of the
**ceiling** — needs a product decision:

| Reading | `$5M floor` | `$200M ceiling` | Effect |
|---|---|---|---|
| **A (recommended): band on the qualifying peak** | observed-peak / verified MC ever ≥ $5M | observed-peak / verified MC ever ≤ $200M | a token that ever printed > $200M is permanently excluded |
| **B: floor on peak, ceiling on current** | ever ≥ $5M | *current* MC ≤ $200M | a token that pumped to $400M and fell back to $80M stays in |

The literal requirement ("`5M ≤ market_cap ≤ 200M`", "observed market cap") reads
as **A**. Reading **B** better matches "show me mid-cap memecoins right now".
Flag for the user; the recon does not decide it.

Mechanically this is **one extra clause** in the existing qualification check
(`… AND peak_value ≤ 200_000_000`) plus the volume/liquidity gates in the age
filter — no schema change.

---

## 9. Comparison With Current Discovery (Step 14)

| Dimension | **Current: profiles + boosts + curated search terms + trending-meta *slugs*** | **Proposed: trending-metas-first (`/metas/trending/v1` + `/metas/meta/v1/{slug}`)** |
|---|---|---|
| **Candidate source** | paid profiles + paid boosts + ~25 keyword `/latest/dex/search` queries (≤ 30 rows each) | every pair inside the 18 trending narratives |
| **Candidate volume / run** | ~540 unique (dev: 536–538) | ~390 unique |
| **Relevance** | mixed — keyword search pulls many dead/tiny tokens; profiles/boosts skew to *whoever paid* | **high** — every candidate is a live member of a DexScreener-curated trending narrative, with real volume |
| **Chain coverage** | ~20 chains touched, but **skewed**: dev `chains_discovered` ≈ `solana 240, robinhood 105, bsc 63, ethereum 47, base 32, icp 17, …` | 8 chains, **less skewed**: `solana 256, bsc 56, ethereum 38, base 29, robinhood 7, …` — better ETH/BSC/Base, far less robinhood noise |
| **"Trending" fidelity** | none — keyword match ≠ trending | narrative-level trending (official); **not** DexScreener's per-pair `trendingScoreH6` |
| **API cost / run** | ~85 calls (4 on 60/min bucket + ~20 search + ~60 enrich) | ~19 calls on the **60/min** bucket + ~50–70 `/token-pairs/v1` on the 300/min bucket ≈ **~70–90 calls** |
| **Freshness** | 60 s CDN cache; keyword lists are static | 60 s CDN cache; **meta membership itself updates continuously** (observed: `tokenCount` for `trump` swung 6 ↔ 100 between calls) |
| **Reliability** | high — 100% documented API | high — 100% documented API |
| **Implementation complexity** | already built | **low** — swap the DISCOVER step's sources; NORMALIZE / FILTER / PERSIST / qualification all unchanged. Delete or demote the `SearchTermEngine`. |
| **Coverage blind spot** | non-paying, non-keyword launches | a trending token not tagged into any of the 18 metas; brand-new tokens before they're metas-tagged |

**Net:** the trending-metas approach is **more relevant, better-balanced across
chains, cheaper on the tighter rate bucket, and simpler**, at the cost of
depending on DexScreener's 18-narrative curation and losing the long-tail keyword
reach.

---

## 10. Recommended Sprint 1 Architecture

### Recommendation: **B — Official REST + official website data, where "website
data" means the documented `/metas/*` endpoints (not the WS).**

Concretely, **Trending-metas-first discovery on the documented API**, with the
current keyword engine kept as an optional fallback:

```
DISCOVER
  primary:   GET /metas/trending/v1  →  GET /metas/meta/v1/{slug} ×N     [OFFICIAL_DOCUMENTED_API]
  keep also: GET /token-profiles/latest/v1, /token-boosts/latest/v1, /token-boosts/top/v1
             (cheap "what launched / got promoted today" signal; already wired)
  fallback:  the existing SearchTermEngine + /latest/dex/search  — OFF by default,
             a config flag to widen coverage when metas look thin
        ↓
NORMALIZE / DEDUPE          (unchanged)
        ↓
PRE-FILTER (free, on meta data):  chain allow-list · marketCap in [5M,200M] · vol>0 · liq>0 · loose age
        ↓
ENRICH survivors: GET /token-pairs/v1/{chain}/{token}   (unchanged mechanism)
        ↓
STRICT FILTER: earliest-pair age ≤ 30d · primary-pair marketCap in [5M,200M] · vol>0 · liq>0
        ↓
EXISTING PIPELINE UNCHANGED:
  PERSIST Token+Snapshot → observed peak → CURRENT_OBSERVATION → HISTORICAL LOOKUP
  → QUALIFICATION (CURRENT_OBSERVATION | HISTORICAL_VERIFIED only) → EVIDENCE
```

**Why not the others:**

- **A (Official REST only, no metas):** that's essentially today's keyword
  pipeline — no trending signal at all. Rejected: doesn't meet the requirement.
- **C (Official REST + undocumented Trending WS):** gives the *real* trending
  ranking but violates *free / no-scraping / no-browser-automation /
  maintainable* — Cloudflare wall, binary frames, silent breakage. Rejected.
- **D (Official REST + run a headless browser to hold the WS):** same downsides
  as C plus a browserless/Chromium service to operate and keep un-blocked.
  Rejected for Sprint 1.
- **E (not reproducible, use an approximation):** correct diagnosis, but **B is
  that approximation** and it's a good one — name it explicitly rather than
  leaving it vague.

### Optional enhancement (still 100% documented API)

Compute a **per-token trending heat score** ourselves from each pair's
`volume.{m5,h1,h6,h24}`, `txns.{…}`, `priceChange.{…}` and rank the main list by
it — a transparent, documented, tunable stand-in for `trendingScoreH6`. This is
*our* score, clearly labelled as such (consistent with the project rule: "Do not
call a token the 'main coin' unless a documented ranking rule selected it").

---

## 11. Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| 1 | **The 18 trending metas are DexScreener-curated**, not an exhaustive trending list. A trending token outside all 18 narratives is missed. | high | Keep profiles/boosts feeds; keep the keyword engine as a config-flag fallback; document "sample, not census" (already a project stance). |
| 2 | `/metas/trending/v1` membership is **volatile** (`tokenCount` swings run-to-run). | high | This is a *feature* for "trending" but makes the candidate set noisy — dedupe + upsert on `(chain_id, token_address)` (already done); a token dropping out of metas doesn't un-persist it. |
| 3 | `/metas/*` is documented but **niche** — DexScreener could change or drop it with less ceremony than the core `/latest/dex/*` endpoints. | low–med | It's still the documented API (versioned `/v1`, 60/min bucket, in the reference). Abstract discovery sources behind an interface (the pipeline already tags `sources`), so swapping back to keyword-first is a config change. |
| 4 | No official **per-chain** trending on the documented API — we filter `chainId` post-hoc, so per-chain "top N" is *our* slice, not DexScreener's. | med | Acceptable; label the list as "our filtered view of DexScreener trending narratives". |
| 5 | `marketCap` still **null / FDV-diverges** on some pairs (unchanged from `dexscreener-reconnaissance.md §5`). | med | Existing rule: null `marketCap` → excluded from the band; never substitute FDV; `HISTORICAL_ESTIMATE` never qualifies. |
| 6 | Someone later "just adds the WS to get real trending". | med | This doc: the WS is `UNDOCUMENTED_INTERNAL_ENDPOINT` behind Cloudflare with a binary frame — **do not build production logic on it without an explicit, signed-off risk decision.** |
| 7 | `/metas/meta/v1/{slug}` returns one representative pair per token — age from that single pair can be wrong (new pool on an old token). | med | Step 5 enrichment via `/token-pairs/v1` computes `min(pairCreatedAt)` across *all* pairs before the strict age gate (unchanged logic). |
| 8 | The `$200M` ceiling on *observed peak* permanently excludes a token that ever spiked higher. | product decision | §8.3 — confirm reading A vs B with the user. |

---

## 12. Decision

### Can we reliably reproduce DexScreener Trending across chains using a free programmatic source?

**No — not the actual Trending *table* (per-pair `trendingScoreH6` ranking).**
That lives only on `wss://io.dexscreener.com/dex/screener/v7/…`, which is an
**undocumented, versioned, binary, Cloudflare-bot-walled internal endpoint**.
Reaching it requires browser automation or a paid third-party wrapper, it is not
supported by DexScreener, and it can break without notice. It fails every one of
*free / no key / no scraping / no browser automation / maintainable*.

**Yes — we can reliably reproduce DexScreener's *trending narratives* and build a
strong trending-first discovery on the free, documented API:**
`GET /metas/trending/v1` → `GET /metas/meta/v1/{slug}` gives ~400 tokens/run that
are live members of DexScreener's own trending narratives, with full market data
(`marketCap`, `volume`, `liquidity`, `pairCreatedAt`) across ~8 chains, at ~19
calls on the 60/min bucket. Filter that set to `5M ≤ marketCap ≤ 200M`,
`age ≤ 30d`, `volume > 0`, `liquidity > 0`, enrich the survivors, and feed the
existing qualification pipeline unchanged.

**Recommended path: Option B** — documented REST + the documented `/metas/*`
"website data" endpoints; **no** undocumented WS, **no** browser automation, **no**
new provider. Keep the existing keyword engine as an off-by-default fallback.

---

## Final Report (the 9 questions)

1. **Is an official Trending API available?**
   No official *trending-pairs* / `trendingScore` API. The only documented
   "trending" is `GET /metas/trending/v1` (narrative aggregates) + its companion
   `GET /metas/meta/v1/{slug}` (the narrative's member pairs, full market data).

2. **Exact mechanism used by the website:**
   Documented `api.dexscreener.com` REST for search/detail, **plus** an
   undocumented `wss://io.dexscreener.com/dex/screener/v7/pairs/h24/1?rankBy[key]=trendingScoreH6&rankBy[order]=desc[&filters[chainIds][n]=…]`
   WebSocket (binary frames, Cloudflare-bot-walled) for the live Trending table,
   **plus** `io.dexscreener.com/metas/v1/trending` for the narrative bar, **plus**
   one labelled `cfw.dexscreener.com` native ad.

3. **Can it be queried per chain?**
   The WS: yes (`filters[chainIds][n]`), but unusable. The documented `/metas/*`:
   no — cross-chain; we filter `chainId` ourselves (same as today). No official
   `/chains` list — keep a curated allow-list + record chains actually seen.

4. **Can it provide trending 5m / 1h / 6h / 24h?**
   DexScreener's own `trendingScore` per timeframe: WS only (unusable). From the
   documented API we get per-meta `marketCapChange.{m5,h1,h6,h24}` and per-pair
   `volume` / `priceChange` / `txns` at all four buckets — enough to build **our
   own** timeframe ranking, not DexScreener's.

5. **Is the topbar reproducible?**
   The **narrative bar**: yes — `GET /metas/trending/v1` returns the same 18
   metas in the same order. The single trailing **Ad** is paid and labelled —
   skip it. The main **Trending table** below the bar: not reproducible for free.

6. **Recommended implementation path:**
   **Trending-metas-first on the documented API (Option B):**
   `/metas/trending/v1` → `/metas/meta/v1/{slug}` as the primary DISCOVER source;
   keep profiles/boosts; pre-filter on the free market data; enrich survivors via
   `/token-pairs/v1`; feed the existing NORMALIZE → FILTER → PERSIST →
   qualification pipeline unchanged. Optionally add a self-computed, clearly
   labelled trending-heat score for ranking.

7. **Expected reliability:**
   High. 100% documented, keyless, free API; `/latest/dex/*` at 300/min and
   `/metas/*` at 60/min, well within budget (~70–90 calls/10-min run). Main
   dependency risk is DexScreener's curation of the 18 metas and the relative
   niche-ness of `/metas/*` vs the core endpoints — mitigated by keeping other
   documented sources wired and abstracting the discovery step.

8. **Should keyword discovery remain as a fallback?**
   **Yes — as an off-by-default, config-flagged fallback.** It adds long-tail
   coverage for tokens outside the 18 trending narratives, it's already built and
   tested, and it's the safety net if `/metas/*` degrades. It should no longer be
   the *primary* source.

9. **Exact $5M–$200M filter recommendation:**
   - Applies to a **verified / observed market cap** (`marketCap`), **never
     FDV**; `HISTORICAL_ESTIMATE` still never qualifies.
   - **Pre-filter** on the free meta-pair `marketCap` **before** enrichment
     (`marketCap` present AND `5_000_000 ≤ marketCap ≤ 200_000_000` AND
     `volume.h24 > 0` AND `liquidity.usd > 0`, plus a loose `age ≤ ~35d`
     single-pair pre-gate). Filtering before enrichment saves ~330 calls/run.
   - **Strict re-check after enrichment** on the primary (max-liquidity) pair:
     `earliest_pair_created_at` present AND `age ≤ 30d`; primary-pair `marketCap`
     in `[5M, 200M]`; `volume.h24 > 0`; `liquidity.usd > 0`.
   - Then the existing qualification: `CURRENT_OBSERVATION` (observed-peak MC) or
     `HISTORICAL_VERIFIED` (CoinGecko MC), each now **also** required to sit
     `≤ $200M`. One extra clause; no schema change.
   - **Open product decision (§8.3):** does the `$200M` ceiling apply to the
     *observed peak* (reading A, literal) or to the *current* market cap
     (reading B)? Confirm before implementing.

---

## Sources

- `https://docs.dexscreener.com/api/reference` — official API reference (fetched 2026-08-31; no trending-pairs endpoint, no WebSocket).
- Live `curl` + headless-Chrome network capture of `dexscreener.com`, `/solana`, `/base`, `/ethereum` (2026-08-31).
- Live `curl` of `api.dexscreener.com/metas/trending/v1` and `/metas/meta/v1/{slug}` (2026-08-31).
- Community reverse-engineering projects (context only, not relied on): `github.com/itsdarkerinnit/dexscraper`, `github.com/doffn/Dexscreen-scraper`, `github.com/muhammetakkurtt/dexscreener-realtime-client`, `gist.github.com/sashaboulouds/d6cf5e034e2a505c2337a26e76cf2a83` — all confirm `io.dexscreener.com` WS + `trendingScoreH6` + binary frames, all unofficial.
