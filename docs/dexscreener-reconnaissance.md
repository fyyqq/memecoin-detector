# DexScreener API Reconnaissance

**Status:** research / reconnaissance only. No integration code, no tables, no UI.
**Date performed:** 2026-08-27
**Base URL:** `https://api.dexscreener.com`
**Official docs:** https://docs.dexscreener.com/api/reference
**Sprint 1 requirement under test:** *"Discover newly launched memecoins within 30
days with market cap ≥ $5M across supported chains."*

All endpoints below were verified live with `curl` on 2026-08-27. No HTML was
scraped. Raw payloads are not pasted; only representative fields are shown.

> **Implementation notes (2026-08-28)** — the Sprint 1 service
> ([sprint-1-discovery.md](sprint-1-discovery.md)) refines this research:
>
> 1. **Qualification changed from "current market cap ≥ $5M" to "observed peak
>    market cap ≥ $5M".** A token qualifies if age ≤ 30 days **and our own stored
>    snapshots have ever recorded MC ≥ $5M** — current MC may be lower. This adds
>    minimal persistence (`tokens`, `market_snapshots`). §5's market-cap handling
>    (nullable, FDV never substitutes, store both) still holds; §8's `FILTER` step
>    and §10's "keep tokens whose marketCap ≥ $5M" are superseded by the
>    observed-peak rule + the cold-start caveat in the Sprint 1 doc.
> 2. Enrichment (`/token-pairs/v1`) runs in **bounded concurrent batches**
>    (default 10 in flight, far under 300 req/min, not an unbounded fan-out) so a
>    cold call returns in ~20–30 s instead of ~80 s.
>
> Everything else below still matches the implementation.

---

## 1. Relevant Endpoints

Legend for **Role**:
- **DIRECT DISCOVERY** — returns market rows we can filter into candidates
- **ACTIVITY DISCOVERY** — returns a token list biased by an activity signal (paid
  boosts/profiles); needs a second call to get market data
- **ENRICHMENT** — needs a token/pair we already have; adds market or metadata

| # | Endpoint | Method | Params | Rate limit | Purpose | Role |
|---|----------|--------|--------|-----------|---------|------|
| 1 | `/latest/dex/search` | GET | `q` (string, required) | 300/min | Free-text search over pairs; returns up to 30 full pair objects | ACTIVITY/keyword DISCOVERY + ENRICHMENT |
| 2 | `/latest/dex/pairs/{chainId}/{pairId}` | GET | path: `chainId`, `pairId` | 300/min | One pair by address; full market object | ENRICHMENT |
| 3 | `/latest/dex/tokens/{tokenAddresses}` | GET | path: comma-list of token addrs (legacy, no chain) | 300/min | All pairs for token(s), up to 30 | ENRICHMENT |
| 4 | `/token-pairs/v1/{chainId}/{tokenAddress}` | GET | path: `chainId`, `tokenAddress` | 300/min | All pairs for one token on one chain, up to 30 full pair objects | ENRICHMENT (primary) |
| 5 | `/tokens/v1/{chainId}/{tokenAddresses}` | GET | path: `chainId`, comma-list of token addrs | 300/min | Pair objects for the given tokens on one chain | ENRICHMENT |
| 6 | `/token-profiles/latest/v1` | GET | none | 60/min | Most recently *created/updated* token profiles (paid listing enhancement). ~30 items, `chainId` + `tokenAddress` only | ACTIVITY DISCOVERY |
| 7 | `/token-boosts/latest/v1` | GET | none | 60/min | Most recent paid "boosts". ~30 items, `chainId` + `tokenAddress` + boost amounts | ACTIVITY DISCOVERY |
| 8 | `/token-boosts/top/v1` | GET | none | 60/min | Tokens with the largest cumulative boost. ~30 items | ACTIVITY DISCOVERY |
| 9 | `/community-takeovers/latest/v1` | GET | none | 60/min | Tokens flagged as community takeovers, with `claimDate` | ACTIVITY DISCOVERY (niche) |
| 10 | `/orders/v1/{chainId}/{tokenAddress}` | GET | path: `chainId`, `tokenAddress` | 60/min | Paid order / boost status for one token. Returned `{"orders":[],"boosts":[]}` in test | ENRICHMENT (not needed for Sprint 1) |
| 11 | `/metas/trending/v1` | GET | none | 60/min | Trending "metas" (narratives) with aggregate marketCap/volume/tokenCount — **not** individual tokens | Narrative context only (future) |
| 12 | `/metas/meta/v1/{slug}` | GET | path: `slug` | 60/min | One narrative + its member `pairs` | Narrative context only (future) |
| 13 | `/token-profiles/recent-updates/v1` | GET | none | 60/min | Recently edited profiles | ACTIVITY DISCOVERY (weak) |
| 14 | `/ads/latest/v1` | GET | none | 60/min | Paid ad placements | Not useful |

### Endpoints that do NOT exist (verified against docs + live probing)

- **No global "list all pairs" / "list all new pairs" endpoint.** There is no
  `/pairs/new`, no `/latest/dex/pairs` without a pair id, no chain-wide pair feed.
- **No pagination** on `/latest/dex/search` (no `limit`, `offset`, `page`,
  `cursor`). It is hard-capped at ~30 results and `q` is required.
- **No chain filter** on `/latest/dex/search` — `q=solana` still returns bsc,
  ethereum, ton, cronos, robinhood rows.
- **No "sort by pairCreatedAt"** anywhere.
- No documented rate-limit headers are returned (checked response headers:
  only Cloudflare `cf-cache-status`, `cache-control: public, max-age=60`).

---

## 2. Verified Response Fields

From a full pair object (endpoints 1–5). Classification: **AVAILABLE** /
**PARTIALLY_AVAILABLE** (present most of the time, can be null) / **NOT_AVAILABLE**.

### Token

| Field | Path | Class | Notes |
|-------|------|-------|-------|
| address | `baseToken.address` | AVAILABLE | |
| symbol | `baseToken.symbol` | AVAILABLE | |
| name | `baseToken.name` | AVAILABLE | |

### Pair

| Field | Path | Class | Notes |
|-------|------|-------|-------|
| chainId | `chainId` | AVAILABLE | string slug, e.g. `solana`, `ethereum`, `bsc`, `base` |
| dexId | `dexId` | AVAILABLE | e.g. `raydium`, `uniswap`, `pancakeswap`, `aerodrome` |
| pairAddress | `pairAddress` | AVAILABLE | |
| pairCreatedAt | `pairCreatedAt` | **PARTIALLY_AVAILABLE** | Unix **ms**. Present on most pairs; observed **null** (e.g. BRETT/base via `/tokens/v1`). See §4. |

### Market

| Field | Path | Class | Notes |
|-------|------|-------|-------|
| priceUsd | `priceUsd` | AVAILABLE | string |
| priceNative | `priceNative` | AVAILABLE | string |
| marketCap | `marketCap` | **PARTIALLY_AVAILABLE** | Present in every test row, but DexScreener documents it as optional and it is derived from circulating supply, which is not always known. See §5. |
| fdv | `fdv` | **PARTIALLY_AVAILABLE** | Present in every test row. Often equals `marketCap` when circulating ≈ total supply. |
| liquidity.usd | `liquidity.usd` | **PARTIALLY_AVAILABLE** | Present in all tests; can be absent on broken/illiquid pairs. Also `liquidity.base`, `liquidity.quote`. |
| volume | `volume.{m5,h1,h6,h24}` | AVAILABLE | USD. `h24` always present. |
| priceChange | `priceChange.{m5,h1,h6,h24}` | **PARTIALLY_AVAILABLE** | Buckets with no trades are omitted (saw `priceChange` with only `h6`,`h24`). |
| txns | `txns.{m5,h1,h6,h24}.{buys,sells}` | AVAILABLE | `h24` always present. |

### Metadata

| Field | Path | Class | Notes |
|-------|------|-------|-------|
| info | `info` | PARTIALLY_AVAILABLE | Object. ~22/30 search rows had it. |
| websites | `info.websites[]` | PARTIALLY_AVAILABLE | `{label,url}` |
| socials | `info.socials[]` | PARTIALLY_AVAILABLE | `{type,url}` (twitter, telegram, …) |
| image / header | `info.imageUrl`, `info.header`, `info.openGraph` | PARTIALLY_AVAILABLE | |
| boosts | `boosts.active` | PARTIALLY_AVAILABLE | Present only on boosted pairs (1/30 in test). Integer count. |

### Discovery-list objects (endpoints 6–9) — much thinner

Only: `url`, `chainId`, `tokenAddress`, `description`, `icon`, `header`,
`links[]`, and for boosts `amount` / `totalAmount`, for takeovers `claimDate`.
**No price, no marketCap, no pairCreatedAt.** Every candidate from these lists
must be enriched via endpoint 4.

---

## 3. Live API Test Results

| Endpoint | Call | Result |
|----------|------|--------|
| search | `?q=pepe` | HTTP 200, 30 pairs, chains: robinhood 13, solana 9, base 4, pulsechain 2, ethereum 1, tron 1. All 30 had `marketCap`, `fdv`, `pairCreatedAt`. |
| search | `?q=WIF` | HTTP 200, mixed chains (bsc, cronos, ethereum, robinhood, solana, ton). Confirms `q` is not a chain filter. |
| tokens/v1 | `solana/DezXAZ8…B263` (BONK) | HTTP 200, `marketCap 275,020,710`, `fdv 277,733,623`, `pairCreatedAt 1671980424000` (2022-12-25). |
| tokens/v1 | `ethereum/0x6982…1933` (PEPE) | HTTP 200, `marketCap 1,646,963,460`, `fdv` equal, `pairCreatedAt 1681492871000`. |
| tokens/v1 | `bsc/0x0E09…cE82` (CAKE) | HTTP 200, `marketCap 561,055,613`, `fdv 592,912,252` (differ), `pairCreatedAt 1680607061000`. |
| tokens/v1 | `base/0x532f…42E4` (BRETT) | HTTP 200, `marketCap 53,319,567`, `fdv` equal, **`pairCreatedAt: null`**. |
| token-pairs/v1 | `solana/BONK` | HTTP 200, 30 pairs. Same token, `pairCreatedAt` ranges 2021→2026 across pairs (multi-pair age problem, see §4). |
| latest/dex/pairs | `solana/5zpyutJu…uyA9` | HTTP 200, `{pair, pairs, schemaVersion}`, single enriched pair. |
| token-profiles/latest/v1 | — | HTTP 200, 30 items. Chains: solana 16, robinhood 12, bsc 1, tron 1. Fields: `chainId`, `tokenAddress`, `links` only. |
| token-profiles → token-pairs/v1 | fresh solana profile token | HTTP 200, 2 pairs, `pairCreatedAt` = today, `marketCap ≈ $41k`. Demonstrates the discovery→enrich chain and that profile tokens are typically far below $5M. |
| token-boosts/latest/v1 | — | HTTP 200, 30 items, `amount`/`totalAmount` present. Chains: solana 20, robinhood 8, bsc 1, polygon 1. |
| token-boosts/top/v1 | — | HTTP 200, 30 items, `totalAmount` present. |
| community-takeovers/latest/v1 | — | HTTP 200, items with `claimDate` (ISO 8601 UTC). |
| metas/trending/v1 | — | HTTP 200, narrative aggregates only (no token rows). |
| orders/v1 | `solana/BONK` | HTTP 200, `{"orders":[],"boosts":[]}`. |

Observations:
- `/latest/dex/search` and friends return `Cache-Control: public, max-age=60` and
  are served through Cloudflare — responses can be up to ~60 s stale.
- No `X-RateLimit-*` headers. Limits are enforced but not surfaced; a 429 with
  `Retry-After` is the documented failure mode.

---

## 4. 30-Day Age Detection

**Field:** `pairCreatedAt`.

- **Format:** Unix timestamp in **milliseconds**, UTC epoch. Example
  `1786895646000` → `2026-06-… UTC`. Divide by 1000 before using seconds-based
  date libraries.
- **Meaning:** timestamp of **DEX pair (pool) creation**, i.e. when that specific
  liquidity pool first appeared on that DEX. It is **not** the token mint/deploy
  time and **not** a "token launch date".
- **Age formula:** `age_days = (now_ms − pairCreatedAt) / 86_400_000`.
  Sprint 1 keep-if `age_days <= 30`.

### Multiple pairs per token

A token has many pairs; each has its own `pairCreatedAt`. Verified with BONK:
the same token has pools created in 2021, 2022, 2023 **and** 2026. Therefore:

- The **oldest** `pairCreatedAt` across a token's pairs is the best available
  proxy for "how long this token has been tradable".
- Filtering on a single pair's age would wrongly flag an old token as "new" the
  moment someone spins up a fresh pool for it.
- **Rule for Sprint 1:** compute token age as `min(pairCreatedAt)` over all pairs
  returned by `/token-pairs/v1/{chain}/{token}`. Only treat the token as a
  candidate if that minimum is ≤ 30 days old.

### `pairCreatedAt` can be null

Observed null for an established token (BRETT on base via `/tokens/v1`). When
**every** pair for a token has null `pairCreatedAt`, age is **unknown**. Sprint 1
must **exclude** such tokens from the "new" list rather than guess (never
back-fill, never assume "new").

### Honesty constraint

Do not label `pairCreatedAt` as "token creation date" anywhere in code, storage,
or UI. Store it as `pair_created_at` and expose the derived value as
`earliest_pair_created_at` / "first seen trading". True on-chain token deploy time
is **NOT_AVAILABLE** from this API.

---

## 5. Market Cap Detection

**Field:** `marketCap` (and `fdv`).

- In every live test row `marketCap` was present and non-null, including a
  0-liquidity-ish pair. However DexScreener officially documents both
  `marketCap` and `fdv` as optional.
- **`marketCap`** = `priceUsd × circulating supply` (when circulating supply is
  known to DexScreener).
- **`fdv`** = `priceUsd × total supply`.
- When circulating ≈ total (common for fair-launch memecoins with no locked/team
  allocation), `marketCap == fdv` (seen for PEPE, BRETT, the fresh pump.fun
  token). When supply is partially locked/vested they differ (CAKE:
  MC 561M vs FDV 593M).
- **`marketCap` can be null / stale** when circulating supply is unknown or
  mis-reported. In that situation `fdv` may be the only size figure.

### Sprint 1 rule

- Filter is **`marketCap >= 5,000,000 USD`**, using the pair with the **highest
  `liquidity.usd`** as the token's representative pair (the "primary pair").
- If `marketCap` is **null** but `fdv` is present: **do not silently substitute
  FDV.** Either (a) exclude the token from the ≥ $5M list, or (b) include it only
  with an explicit `size_basis = "fdv"` flag and `market_cap = null` stored
  separately. Never let an FDV value flow into a field named `market_cap`.
- Always store **both** `market_cap` and `fdv` when present, plus which one the
  filter used (`size_basis` ∈ `market_cap` | `fdv` | `unknown`).
- Edge case — only FDV present: token is *reported* separately as "FDV ≥ $5M,
  market cap unknown", never merged into the main result set silently.

---

## 6. Cross-Chain Discovery Strategy

**There is no single global "all memecoins" endpoint.** `/latest/dex/search`
requires a `q`, returns ≤ 30 rows, and has no chain filter or pagination. So we
cannot and must not claim we scan every token.

### Classification of available mechanisms

**A. DIRECT DISCOVERY (market rows, filterable immediately)**
- `/latest/dex/search?q=<term>` — the only endpoint that returns full market
  objects without already knowing a token. Coverage is entirely a function of the
  query terms. Naturally multi-chain.

**B. DISCOVERY THROUGH ACTIVITY SIGNALS (token list → must enrich)**
- `/token-profiles/latest/v1` — newest paid profiles. In testing these skew to
  brand-new, tiny (<$100k) tokens and to solana. Good "what launched today"
  signal; most will fail the $5M filter.
- `/token-boosts/latest/v1` and `/token-boosts/top/v1` — tokens whose owners paid
  for promotion. Biased toward whoever spends money; multi-chain but not
  representative.
- `/community-takeovers/latest/v1` — niche.

**C. ENRICHMENT ONLY (need a token/pair already)**
- `/token-pairs/v1/{chain}/{token}` — primary enrichment call (all pairs, market
  data, per-pair `pairCreatedAt`).
- `/tokens/v1/{chain}/{tokens}` (batch), `/latest/dex/pairs/{chain}/{pairId}`,
  `/latest/dex/tokens/{tokens}` (legacy).
- `/orders/v1/...` — paid-order status; not needed for Sprint 1.

### Realistic Sprint 1 answer

> "The detector discovers candidates from (1) DexScreener's recent
> **token-profiles** and **token-boosts** feeds — i.e. tokens with fresh listing
> activity — and (2) a **maintained list of memecoin search terms** run against
> `/latest/dex/search` (e.g. `pepe`, `doge`, `cat`, `wif`, chain names, current
> meta slugs from `/metas/trending/v1`). Each raw candidate is then enriched via
> `/token-pairs/v1` and filtered by age and market cap. This is an
> **activity-and-keyword-driven sample across chains, not an exhaustive scan** of
> every token on every blockchain."

### Chains

`chainId` is a string slug carried on every pair. Sprint 1 "supported chains" =
whichever slugs we choose to keep after normalization; a starting set backed by
live results is `solana`, `ethereum`, `bsc`, `base` (all seen returning full
market data). Others (`polygon`, `tron`, `ton`, `pulsechain`, `cronos`,
`robinhood`, …) appear too and can be allow-listed later. No API call is
per-chain for discovery; we just filter the mixed results.

---

## 7. Rate Limits

Published limits (docs + corroborated by third-party references; not exposed as
response headers):

| Group | Endpoints | Limit |
|-------|-----------|-------|
| DEX / pairs | `/latest/dex/search`, `/latest/dex/pairs/*`, `/latest/dex/tokens/*`, `/token-pairs/v1/*`, `/tokens/v1/*` | **300 requests / minute** |
| Profiles / boosts / orders | `/token-profiles/*`, `/token-boosts/*`, `/orders/v1/*`, `/community-takeovers/*`, `/metas/*` | **60 requests / minute** |

No API key. No documented daily cap. Free.

### Budget for a conservative poll

Assume one discovery cycle every **10 minutes** (6 cycles/hour):

| Step | Calls per cycle |
|------|-----------------|
| `/token-profiles/latest/v1` | 1 |
| `/token-boosts/latest/v1` | 1 |
| `/token-boosts/top/v1` | 1 |
| `/metas/trending/v1` (for meta search terms) | 1 |
| `/latest/dex/search` × ~20 curated terms | 20 |
| `/token-pairs/v1/{chain}/{token}` enrichment — de-duped unique candidates, cap at ~60 | ≤ 60 |
| **Total** | **~85 calls / cycle** |

- Per hour: ~85 × 6 = **~510 calls/hour**.
- Per day: **~12,000 calls/day**.
- Peak burst is the enrichment fan-out (≤ 60 calls) — spread over a few seconds
  it is far under 300/min. The 60/min bucket sees only 4 calls/cycle.
- Verdict: a 10-minute cycle sits at **well under 5%** of the per-minute budget.
  Even a 2-minute cycle is fine. No aggressive polling proposed.

Add: respect `Retry-After` on 429, cache responses for their `max-age=60`, and
never parallelize beyond a handful of in-flight requests.

---

## 8. Proposed Sprint 1 Discovery Pipeline

`DISCOVER → NORMALIZE → FILTER → DEDUPLICATE → STORE`

### 1. DISCOVER — build a raw candidate set `{chainId, tokenAddress}`

- `GET /token-profiles/latest/v1` → take `chainId` + `tokenAddress`.
- `GET /token-boosts/latest/v1` and `/token-boosts/top/v1` → same.
- `GET /metas/trending/v1` → take `slug`/`name` values as extra search terms.
- `GET /latest/dex/search?q=<term>` for each term in a **small curated list**
  (memecoin words + chain names + trending meta slugs). From each response, take
  `baseToken.address` + `chainId` of every returned pair.
- Union all of the above. This is explicitly a **sample**, not a full scan.

### 2. ENRICH — one call per unique token

- `GET /token-pairs/v1/{chainId}/{tokenAddress}` → array of pair objects.
  (Search-sourced candidates already carry a pair object, but still enrich so we
  see *all* pairs for correct age + primary-pair selection.)
- Skip / retry-later on non-200; a failed enrichment drops the candidate for this
  cycle (no partial rows).

### 3. NORMALIZE — one internal record per token

```
token_key        = chainId + ":" + lower(tokenAddress)
chain_id         = chainId
token_address    = tokenAddress
symbol,name      = baseToken.symbol / baseToken.name (from primary pair)
primary_pair     = pair with max liquidity.usd
dex_id           = primary_pair.dexId
pair_address     = primary_pair.pairAddress
price_usd        = primary_pair.priceUsd
market_cap       = primary_pair.marketCap        (nullable)
fdv              = primary_pair.fdv               (nullable)
liquidity_usd    = primary_pair.liquidity.usd     (nullable)
volume_h24       = primary_pair.volume.h24
price_change_h24 = primary_pair.priceChange.h24   (nullable)
txns_h24         = buys + sells from primary_pair.txns.h24
earliest_pair_created_at = min(pairCreatedAt) over all non-null pairs (nullable)
pair_count       = number of pairs
size_basis       = "market_cap" if market_cap>=5M
                   else "fdv" if market_cap null and fdv present
                   else "unknown"
source           = which discovery step produced it (profile|boost|search)
data_source      = "dexscreener"
retrieved_at     = now (UTC)
```

### 4. FILTER

- **Age:** keep if `earliest_pair_created_at` is non-null **and**
  `(now − earliest_pair_created_at) ≤ 30 days`. Null → not a "new" candidate
  (excluded, logged as `age_unknown`).
- **Market cap:** keep if `market_cap >= 5_000_000`. If `market_cap` null and
  `fdv >= 5_000_000`, do **not** put it in the main list; record it separately as
  `fdv_only` for transparency.
- (Optional sanity floor, not required by the sprint: drop pairs with
  `liquidity_usd` missing or `< some_small_value` to cut obvious noise — only if
  explicitly agreed.)

### 5. DEDUPLICATE

- Key on `token_key` (`chainId:tokenAddress`), not on pair address, not on
  symbol (symbols collide massively — three different `PEPE` tokens appeared in
  one search response).
- If the same token arrives from multiple discovery sources, keep one record and
  union the `source` values.
- Across cycles: upsert on `token_key`; refresh market fields + `retrieved_at`.

### 6. STORE

- One row per token (Sprint 1 has **no** tables yet — this defines the shape for
  a later migration).
- Persist `market_cap` **and** `fdv` separately, plus `size_basis`.
- Persist `data_source = "dexscreener"` and `retrieved_at` so the UI can show
  provenance + timestamp (Sprint 1 requirement 6).

### Missing-data behaviour (summary)

| Missing | Behaviour |
|---------|-----------|
| `marketCap` null, `fdv` present | Not in main ≥$5M list; separately reported as `fdv_only`. Never copy fdv → market_cap. |
| both `marketCap` & `fdv` null | Excluded; logged `size_unknown`. |
| all `pairCreatedAt` null | Excluded from "new"; logged `age_unknown`. |
| `liquidity.usd` null | Store null; still allowed unless an agreed liquidity floor says otherwise. |
| enrichment call fails | Candidate dropped for this cycle, retried next cycle. |

---

## 9. Known Limitations

Things that prevent the claim *"We scan every memecoin across every blockchain."*

1. **No universal token/pair discovery endpoint.** The only market-data
   discovery path (`/latest/dex/search`) needs a query string, returns ≤ 30
   rows, has no pagination and no chain filter. Coverage = our query list +
   whatever the activity feeds surface. It is a **sample**.
2. **Activity-feed bias.** `/token-profiles/*` and `/token-boosts/*` list tokens
   whose owners **paid** DexScreener (profiles, boosts) or that DexScreener
   editorially flagged (takeovers). Discovery via these is skewed toward
   promoted tokens and, in testing, toward Solana. Non-paying legitimate launches
   can be entirely absent.
3. **`pairCreatedAt` is pair (pool) creation, not token launch.** True on-chain
   token deploy time is not exposed. We approximate launch age with the earliest
   pool creation time; a token that migrates DEX or adds pools later still has an
   old earliest pair, and a genuinely old token that opens a new pool must not be
   mis-flagged as new.
4. **`pairCreatedAt` can be null**, even for established tokens — those tokens
   have unknown age and are excluded from the "new" list rather than guessed.
5. **`marketCap` can be null or stale.** It depends on DexScreener knowing
   circulating supply. When absent we have only FDV, which is a different metric
   and must never be presented as market cap.
6. **`marketCap` vs `fdv` divergence** is real (locked/vested supply). The $5M
   filter result depends on which metric is used; we must always label it.
7. **Multiple pairs per token (up to 30 returned).** Metrics differ per pair;
   choosing the "primary" pair (max liquidity) is a heuristic, and a token with
   >30 pairs is truncated by the API.
8. **Rate limits (300/min, 60/min) and 60 s CDN caching.** Fine for a slow poll,
   but they cap how wide we can fan out and mean data can be up to a minute
   stale. No API key / no SLA on the free tier.
9. **No historical depth.** These endpoints are current-state only. Any
   historical market analysis needs GeckoTerminal (out of scope this sprint).
10. **Chain slugs are arbitrary strings** defined by DexScreener; the supported
    set can change and must be treated as an allow-list we curate.

---

## 10. Recommendation

**The free DexScreener public API is sufficient for Sprint 1** — with the
explicit understanding that Sprint 1 delivers an *activity- and keyword-driven
multi-chain sample* of newly launched memecoins, not an exhaustive scan.

### Recommended endpoints

| Use | Endpoint | Limit |
|-----|----------|-------|
| Discovery — fresh listings | `/token-profiles/latest/v1` | 60/min |
| Discovery — promoted tokens | `/token-boosts/latest/v1`, `/token-boosts/top/v1` | 60/min |
| Discovery — keyword/meta | `/latest/dex/search?q=` (curated term list + meta slugs) | 300/min |
| Meta/narrative term source | `/metas/trending/v1` | 60/min |
| Enrichment (primary) | `/token-pairs/v1/{chainId}/{tokenAddress}` | 300/min |
| Enrichment (batch, optional) | `/tokens/v1/{chainId}/{tokenAddresses}` | 300/min |

### Endpoints rejected (and why)

| Endpoint | Reason |
|----------|--------|
| `/latest/dex/pairs/{chainId}/{pairId}` | Single-pair; `/token-pairs/v1` already returns all pairs for a token. |
| `/latest/dex/tokens/{addresses}` (legacy) | Superseded by chain-scoped `/token-pairs/v1` and `/tokens/v1`. |
| `/orders/v1/{chainId}/{tokenAddress}` | Paid-order status; irrelevant to discovery/filtering. |
| `/community-takeovers/latest/v1` | Too niche/low-volume to base discovery on; revisit later. |
| `/token-profiles/recent-updates/v1` | "Updated" ≠ "new"; weak signal. |
| `/ads/latest/v1` | Ad inventory, not token data. |
| `/metas/meta/v1/{slug}` | Useful later for the narrative graph, not for Sprint 1 discovery. |
| Any "all pairs" / "new pairs" feed | **Does not exist.** |

### Verified fields we can rely on for Sprint 1

`baseToken.{address,symbol,name}`, `chainId`, `dexId`, `pairAddress`,
`priceUsd`, `volume.h24`, `txns.h24`, `liquidity.usd` (usually),
`marketCap` / `fdv` (usually, treat as nullable), `pairCreatedAt` (usually,
treat as nullable, pool-level), `info.{websites,socials}` (partial),
`boosts.active` (boosted pairs only).

### Proposed discovery approach (one line)

Union of DexScreener's recent **profile** + **boost** feeds and a curated
**search-term** sweep → enrich each unique `chainId:tokenAddress` via
`/token-pairs/v1` → keep tokens whose **earliest** pair is ≤ 30 days old and
whose **marketCap** ≥ $5M → dedupe on `chainId:tokenAddress` → store with
`data_source` + `retrieved_at` and both size metrics.

### Important limitations to carry forward

- Discovery is a sample, not a census — say so in the UI.
- `pairCreatedAt` = pool creation, stored as such; no "token creation date".
- Never let FDV populate a `market_cap` field; always label `size_basis`.
- Exclude (don't guess) when age or market cap is unknown.
