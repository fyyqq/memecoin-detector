# Historical Peak Reconnaissance

**Status:** research / reconnaissance only. No production code, no migrations, no
UI changes, no pipeline changes. Nothing here is implemented.
**Date performed:** 2026-08-28
**Sprint 1 requirement under investigation (Step 13B):**

> The detector must identify memecoins that **(1)** are ≤ 30 days old (first
> trading) **and (2)** have **ever** reached market cap ≥ $5M.
>
> - A coin qualifies **immediately** the first time our detector observes
>   current MC ≥ $5M — even minutes after launch.
> - A coin **stays** qualified after MC falls below $5M, as long as it is still
>   within the 30-day age window.
> - **Cold start:** a coin can cross $5M *before* our detector discovers it. Our
>   own snapshots then only see the post-crash value.

Base doc: [dexscreener-reconnaissance.md](dexscreener-reconnaissance.md) ·
[sprint-1-discovery.md](sprint-1-discovery.md).

All API calls below were made live with `curl` on 2026-08-28. Raw payloads are
not pasted — representative fields and exact observations only.

> **Business-rule correction (post-implementation).** The main qualified list
> represents a **verified or observed market cap ≥ $5M** — `CURRENT_OBSERVATION`
> or `HISTORICAL_VERIFIED` only. The `HISTORICAL_ESTIMATE` engine below is still
> built and still runs, but its output is an **FDV-basis estimate**, not a market
> cap: it is an informational secondary signal that does **NOT** qualify a token
> for the main list. See §6 and `sprint-1-discovery.md → "Historical Peak
> Qualification"`.

---

## 1. Problem

Today's qualification (`sprint-1-discovery.md`) uses **observed peak market
cap** — the highest MC *our own `market_snapshots` have recorded* since
`first_observed_at`. For a token first seen *after* its pump, that value is the
post-crash MC and the token is wrongly excluded.

```
Day 1  MC $1M      (we are not watching)
Day 2  MC $8M      (we are not watching)   ← the real peak, crosses $5M
Day 3  MC $2M      detector discovers it   ← our observed peak = $2M  → excluded
```

**Core question:** *what is the fastest and most reliable way to determine
whether a newly launched memecoin previously reached ≥ $5M market cap before our
detector started observing it?*

Two hard constraints from the product rules:

- **Historical OHLCV high ≠ historical market cap.** A price high only becomes a
  market-cap figure with a *defensible supply basis*.
- **Never compute `historical price × current circulating supply`** unless a
  source explicitly provides a historically valid supply basis.
- **`UNKNOWN` must never be shown as "never crossed $5M".**

---

## 2. DexScreener Capabilities

Official docs: https://docs.dexscreener.com/api/reference (re-read 2026-08-28).

**DexScreener has zero historical capability.** The documented endpoint list
contains **no** endpoint for:

- historical price
- OHLCV / candles
- historical market cap
- historical pair or token snapshots
- trade history

Every endpoint is **current-state only**. Confirmed against the docs and against
the earlier live probing in `dexscreener-reconnaissance.md §9` ("No historical
depth. These endpoints are current-state only.").

What DexScreener *does* give us, and we already use:

| Field | Use |
|---|---|
| `pairCreatedAt` (Unix ms, pool creation) | age gate — `min()` across a token's pairs = `earliest_pair_created_at` |
| `marketCap` / `fdv` (current, nullable) | current-state qualification (`CURRENT_OBSERVATION`) |
| `baseToken.address` + `chainId` | token identity for downstream lookups |

Rate limit: DEX/pairs group historically **300 req/min** (recon doc); the docs
page now states **60 req/min** for all groups. No key. No historical anything.

**Verdict:** DexScreener is the discovery + age + current-MC layer. It cannot
answer the cold-start question at all.

---

## 3. GeckoTerminal Capabilities

Base URL `https://api.geckoterminal.com/api/v2`. **No API key.** Free tier rate
limit: **geckoterminal.com/dex-api currently states 10 calls/min** (30/min is
widely cited from earlier docs). In testing, HTTP 429 appeared after ~6–8 rapid
calls. `cache-control: max-age=30, public, s-maxage=60`. Paid tiers: 250–500/min.

### Networks

`GET /networks?page=N` — 100 per page, many pages. Slugs **differ from
DexScreener**: `eth` (not `ethereum`), `bsc`, `solana`, `base`, `arbitrum`,
`polygon_pos`, `avax`, … A **DexScreener-slug → GT-slug mapping table** is
required.

### Token

`GET /networks/{network}/tokens/{address}` — live test, our DB's DOGE token
(`solana/Ci11wAJVj4tMeBo4EJUUKNnejAvHorcktcMSHmSLQdx4`):

```
price_usd 0.0744 · fdv_usd 74,461,580 · market_cap_usd null
total_supply 999999999000000 · normalized_total_supply 999,999,999
coingecko_coin_id null
```

vs BONK (`solana/DezXAZ8…B263`):

```
price_usd 0.00000304 · fdv_usd 267,867,062 · market_cap_usd 268,108,497
coingecko_coin_id "bonk"
```

- **`fdv_usd`** is always computed = `price_usd × total_supply`.
- **`market_cap_usd` is sourced from CoinGecko.** It is `null` whenever the token
  has no `coingecko_coin_id`, **and** (see §4) it is `null` even for
  CoinGecko-listed tokens that lack a verified circulating supply.
- `total_supply` / `normalized_total_supply` are present on this endpoint (they
  are `null` on the `/info` endpoint below — use this one for supply).

`GET /networks/{network}/tokens/{address}/info` — metadata, no supply:
`mint_authority`, `freeze_authority`, `developer_address`,
`developer_holding_percentage`, `holders.{count,distribution_percentage}`,
`is_honeypot`, `gt_score`, socials. **`mint_authority: null` on Solana = mint
revoked = total supply is immutable** — this is the signal that makes a
supply-based estimate defensible over time.

### Pools

`GET /networks/{network}/tokens/{address}/pools` — up to 20 pools, ordered by
liquidity (first = deepest). Live test, DOGE:

```
address 8JWZxNhbU4Svkz5REbUS4z6c1ZZwhcMCAfSrfxL5HBFW
name "DOGE / USDC" · dex "raydium-clmm"
pool_created_at "2026-08-20T06:07:17Z"   ← ISO 8601; matches DexScreener pairCreatedAt exactly
reserve_in_usd 74,166,514 · fdv_usd 74,461,580 · market_cap_usd null
price_change_percentage / transactions / volume_usd buckets
```

- **A. Can we identify the correct pool?** **Yes** — same heuristic as
  DexScreener: highest `reserve_in_usd`.
- `pool_created_at` corroborates the DexScreener age gate (second source).

### OHLCV — the key endpoint

`GET /networks/{network}/pools/{pool}/ohlcv/{day|hour|minute}` — params
`aggregate`, `before_timestamp`, `limit` (max **1000**), `currency`, `token`.

Returns `ohlcv_list`: **`[timestamp, open, high, low, close, volume]`** and
`meta.{base,quote}` (with `coingecko_coin_id`).

> **The list contains PRICE OHLC + volume ONLY. No market cap, no FDV, no
> supply, no reserve/liquidity.**

Live test, DOGE pool (8 days old):

| Timeframe | Candles returned | Span |
|---|---|---|
| `day` | 9 | full pool life (2026-08-20 → 2026-08-28) |
| `hour` | 183 | full pool life (~183 h) |
| `minute` (`before_timestamp` = T−3d) | 331 | back to pool creation, but **sparse** — only periods with trades emit a candle |

Live test, WIF/SOL pool (created 2023-11-20): `day` returned **181 candles,
oldest 2026-03-01** → **free-tier daily history ≈ 6 months**. Hourly/minute
retention is shorter. **For a token ≤ 30 days old this is a non-issue — day and
hour candles cover the entire pool life.** Minute candles may already be thinned
a few days out.

Answers B–I:

- **B. Historical price?** **Yes** — full history since pool creation for any
  ≤ 30-day token, at day and hour granularity. Minute is best-effort / recent.
- **C. Historical market cap directly?** **No.** Not in OHLCV; `market_cap_usd`
  is current-only and usually `null` for fresh memecoins.
- **D. What is missing?** Historical **circulating supply** (and any historical
  MC series). Also historical `reserve_in_usd` / liquidity — OHLCV has neither.
- **E. Can pool liquidity / reserve help?** Only the **current** `reserve_in_usd`
  is exposed. There is no historical reserve series, so historical liquidity
  cannot be reconstructed from this API.
- **F. Max lookback?** day ≈ 6 months; hour/minute shorter but ≥ full life of a
  ≤ 30-day pool. **Full coverage for our use case.**
- **G. Free rate limits?** ~10–30 req/min, no key, no documented daily cap.
- **H. Caching?** `max-age=30` (client), `s-maxage=60` (CDN). Up to ~60 s stale.
- **I. Historical enough for a ≤ 30-day token?** **Yes for price. No for market
  cap.**

### Trades

`GET /networks/{network}/pools/{pool}/trades` — **last 24 h only, ≤ 300 trades.**
Useless for a historical peak that happened days ago.

### Discovery feeds DexScreener lacks

`GET /networks/{network}/new_pools` (20/page, ~10 pages), `.../trending_pools`,
`GET /networks/trending_pools` (all networks), `GET /networks/{network}/pools`
(top), `GET /search/pools?query=`. Live test `solana/new_pools`: 20 newest pools,
`pool_created_at` seconds old, all `market_cap_usd: null`. **A real per-network
new-pool feed** — a meaningful discovery improvement over DexScreener's
activity-feed-only approach (out of scope here, noted for later).

---

## 4. CoinGecko Capabilities

Base URL `https://api.coingecko.com/api/v3`.

- **Public (keyless):** IP-shared limit, ~5–15/min in practice — **HTTP 429
  after ~6 rapid calls** in testing. `/onchain/*` and `/coins/list/new` **require
  a Demo API key**.
- **Demo API key (free, registration):** ~30 req/min, 10k calls/month
  (CoinGecko has quoted 30–100/min for Demo at different times).
- **`market_chart` / `market_chart/range`:** free/Demo capped at **365 days** of
  history. Granularity auto: ≤ 1 day → 5-min, 2–90 days → hourly, > 90 → daily.
  For a ≤ 30-day token: **hourly market-cap series** — *if the token qualifies
  below*.

### The listing requirement (the critical distinction)

`GET /coins/{platform}/contract/{address}`:

| Token | Result |
|---|---|
| PEPE `ethereum/0x6982…1933` (listed) | **200** — `market_data.market_cap.usd 1,594,581,076`, `circulating_supply == total_supply` |
| our DOGE `solana/Ci11…QDx4` (random unlisted memecoin) | **404 `{"error":"coin not found"}`** |

**A newly-launched DEX token that CoinGecko has not indexed returns 404. No coin
id → no `market_chart` → no historical market cap. Nothing.**

### Listing ≠ historical market cap

Even for a **listed** token, `market_chart` only returns real market caps when
CoinGecko has a **verified circulating supply**. Live test — Pistacio
(`solana/FZqdw6oSDCbHtKYxmhnfbi97SnyVy8jaYpdCoMrrjKa2`, ~2 days old, *is*
listed as `pistacio`):

```
/coins/pistacio      circulating_supply 0.0 · market_cap.usd 0.0 · fdv 3,796,032
/coins/pistacio/market_chart?days=7
    price points: 54   → meaningful (max price $0.01144 @ 2026-08-26T05:00)
    market_cap points: 54 → EVERY POINT IS 0
```

The `market_caps` array is **entirely zeros** despite the token being "listed",
because circulating supply is unset. Only `prices` and `fdv` are usable there.

### When CoinGecko *does* deliver

For properly indexed tokens (PEPE, WIF, CHUMP — CHUMP is in our own DB and is
listed as `chump-coin`), `/coins/{id}/market_chart/range` returns a
**timestamped, verified historical market-cap series** (live test WIF: 240
hourly `market_caps` points over 10 days, $135M–$231M range). **This is the gold
standard** — but it exists for only a subset of tokens and effectively never for
a token in its first 24–72 h.

### `/onchain/*` endpoints

CoinGecko's `/onchain/networks/{net}/...` endpoints are **GeckoTerminal data
proxied** → **same limitations** (no historical MC), and they require a Demo key.

### Chain coverage / newly-launched lag

Broad for *listed* tokens (all major chains). Listing is a manual review;
fast-movers are sometimes indexed within 24–72 h (observed `apeonfone`/SOL, 1
day old, already had a `coingecko_coin_id`), many take a week or never. A
`trending_pools` sample had `coingecko_coin_id` on ~16/20 — but trending is
survivorship-biased (already got attention), and several of those still had
zero-filled MC history (Pistacio).

**Critical answer:** CoinGecko **cannot** return historical market cap for a
random newly-launched unlisted DEX token, and returns a **zero-filled** series
for many *listed* memecoins that lack a verified circulating supply.

---

## 5. On-Chain Reconstruction

`historical MC = historical price × historical circulating supply`

| Ingredient | Availability |
|---|---|
| historical price | **Available** — GeckoTerminal OHLCV (§3) |
| historical **total** supply | Usually = current total supply, **when mint is disabled** (Solana `mint_authority: null`; EVM: no `mint()` / ownership renounced). GT `/info` exposes the Solana signal. |
| historical **circulating** supply | **Not available** from any free API as a time series |

To get true historical circulating supply you would need to:

1. Read the token contract's `totalSupply()` **at a historical block** → requires
   an **archive node / archive-capable RPC** (Alchemy / QuickNode / Helius free
   tiers are too limited for regular cross-chain historical queries; public RPCs
   throttle archive calls hard). Effectively a paid dependency.
2. Subtract locked / team / vesting / burn balances **at that block** → requires
   knowing *which addresses to exclude per token*. **There is no generic way** —
   it is deeply project-specific.

- **Total-supply (FDV-basis) reconstruction is feasible:**
  `peak_price (GT OHLCV) × current total_supply`, valid when mint is
  disabled/renounced. This yields a historical **FDV**, not market cap.
- **True circulating-supply (MC-basis) reconstruction is not realistic for a
  lightweight MVP** — chain-specific, archive-RPC-dependent, per-token address
  classification, ongoing maintenance.

**Assessment: implement the FDV-basis estimate; do not attempt MC-basis on-chain
reconstruction.** Reserve archive lookups as a manual, last-resort tool for
individual high-value tokens.

---

## 6. Evidence Classification

Every "ever ≥ $5M" determination carries exactly one label. Precedence:
**HISTORICAL_VERIFIED > CURRENT_OBSERVATION > HISTORICAL_ESTIMATE > UNKNOWN.**

> ### Market Cap vs FDV — and what qualifies the main list
>
> - **Market cap** = price × **circulating** supply. It is the real size of the
>   asset that is actually tradable. Only a **verified or observed market cap**
>   qualifies a token for the main ≥ $5M universe.
> - **FDV** (fully-diluted valuation) = price × **total** supply. It assumes
>   every token that could ever exist is already in circulation. For a memecoin
>   with a large treasury / vesting / un-minted allocation, FDV can be many times
>   the real market cap.
> - **`HISTORICAL_ESTIMATE` is an FDV-basis estimate** (`peak price × total
>   supply`). It is an **informational secondary signal only** — it does **NOT**
>   qualify a token for the main list, is never stored in
>   `historical_peak_value`, and is never rendered as a "market cap".
> - The **only** qualifying statuses are `CURRENT_OBSERVATION` (our own
>   DexScreener snapshot saw MC ≥ $5M) and `HISTORICAL_VERIFIED` (CoinGecko
>   historical **market cap** ≥ $5M).

| Label | Definition | Source | Qualifies the main list? |
|---|---|---|---|
| **HISTORICAL_VERIFIED** | A provider returned a **non-zero historical market-cap data point ≥ $5M** with a verified circulating-supply basis, timestamped. | CoinGecko `market_chart(/range)` `market_caps[]` | **Yes** |
| **CURRENT_OBSERVATION** | Our own `market_snapshots` recorded MC ≥ $5M (a real market-cap figure, `size_basis = market_cap`) at some point since `first_observed_at`. | our DB | **Yes** |
| **HISTORICAL_ESTIMATE** | Reconstructed: `max(OHLCV high) × total_supply ≥ $5M`, **and** the supply basis is defensible — mint disabled/renounced **and** circulating ≈ total. This is an **FDV-basis** estimate, NOT a market cap. | GeckoTerminal OHLCV + GT token/info | **No** — informational only. Stored (`tokens.historical_estimate_fdv_usd` + `historical_peak_evidences`), shown on the detail page as a clearly-labelled secondary signal, flagged with a confidence score. |
| **UNKNOWN** | None of the above. Insufficient historical evidence. | — | **No** — but **never** rendered as "never reached $5M" |

Notes:

- `CURRENT_OBSERVATION` outranks `HISTORICAL_ESTIMATE` because a real snapshot
  beats a reconstruction; a `HISTORICAL_VERIFIED` external point outranks even
  our own snapshot (it can be earlier / independently sourced).
- FDV-only figures (CoinGecko `fdv`, GT `fdv_usd`, our own `size_basis = fdv`)
  **never** produce `HISTORICAL_VERIFIED` or `CURRENT_OBSERVATION`, and — per the
  correction above — **never qualify the main list at all**. They are recorded as
  `HISTORICAL_ESTIMATE` (via the explicit supply-basis check) purely as an
  informational signal.
- Each determination should persist an evidence record:
  `{ token, label, method, source, source_url, value_usd, basis (market_cap|fdv),
  point_timestamp, confidence, evaluated_at }`, re-evaluated every cycle
  (UNKNOWN → ESTIMATE/VERIFIED as data appears; ESTIMATE → VERIFIED on upgrade).

---

## 7. Candidate Historical Lookup Strategies

Flow: **candidate → token+chain identified → best pool → historical lookup →
historical-peak evidence → qualification status.**

Scores: 1 (poor) – 5 (excellent).

| | A: DexScreener only | B: DS + GeckoTerminal | C: DS + CoinGecko | D: DS + GT + CoinGecko | E: On-chain reconstruction |
|---|---|---|---|---|---|
| **Coverage (cold-start peaks)** | 1 — none | 4 — price history for ~all tokens w/ a GT pool | 2 — only CoinGecko-listed w/ verified supply | **5** — best free combination | 3 — theoretically all, practically gated by address classification |
| **Accuracy** | 5 for *current*, N/A history | 3 — FDV-basis estimate | 5 when present, else nothing | **4** — tiered: verified where possible, estimate elsewhere | 2–4 — depends on locked-supply classification |
| **Latency** | 5 — already in-pipeline | 3 — +2–3 GT calls/candidate, 10–30/min cap | 3 — +1–2 CG calls, brutal keyless limit | 3 — 2–5 calls/candidate, batch w/ backoff | 1 — archive RPC at historical blocks, per chain |
| **Cost** | 5 — free | 5 — free, no key | 4 — free (Demo key) | 4 — free (GT no key + CG Demo key) | 1 — archive RPC ≈ paid |
| **Engineering complexity** | 5 — done | 3 — slug map, OHLCV paging, supply check, peak math | 3 — 404 + zero-array handling | 2 — 3 sources + evidence precedence | 1 — per-chain, archive infra, address heuristics, ongoing |
| **Chain coverage** | 4 | 4 — all our chains (+ slug map) | 4 — listed tokens, broad | **5** — union | 2 — each chain a separate build |
| **Suitability for ≤ 30-day memecoins** | 1 | **5** — GT has full price history since pool creation | 2 — most aren't listed yet | **5** — best achievable with free data | 2 — overkill for an MVP |

- **A** = today's system. Cannot address cold start.
- **B** = the pragmatic upgrade: recovers a defensible *estimate* for almost
  every token, free, no key.
- **C** = high accuracy where it lands, near-zero coverage for genuinely fresh
  tokens.
- **D** = B + C: CoinGecko verifies when it can, GT estimates otherwise, our
  snapshots cover "currently big". **Recommended.**
- **E** = not for the MVP; a manual escalation path for individual tokens.

---

## 8. 15-Coin Benchmark

*"Given 15 memecoins ≤ 30 days old across Solana / Base / Ethereum / BSC / other
chains that DexScreener shows today — how many can we automatically discover and
determine have ever reached ≥ $5M?"*

Using **Strategy D**, realistic expectation:

| Stage | Outcome |
|---|---|
| **Discover at all** | ~10–13 / 15. DexScreener profiles + boosts + curated search **+ GT `new_pools`/`trending_pools` per chain**. A token with no boost, no profile, an unguessable name, and no trending signal can still be missed — coverage is a sample, not a census. |
| **Currently ≥ $5M** → `CURRENT_OBSERVATION` | ~4–6 / 15. Instant qualify on first enrichment. Already works today. Handles "launched 2 days ago, sitting at $8M **now**". |
| **Crossed $5M earlier, now below** (the cold-start cases) | ~3–5 / 15. Of these: |
| &nbsp;&nbsp;• `HISTORICAL_VERIFIED` (CoinGecko) | ~**1** / 15 — a fast-mover that got indexed *with* a circulating-supply basis. Often 0. |
| &nbsp;&nbsp;• `HISTORICAL_ESTIMATE` (GT price × immutable supply, passes concentration check) | ~**3–4** / 15 — GT has full hourly price history for these; converts to a defensible ≥ $5M **FDV** estimate when supply is clean. **These do NOT qualify the main list** (informational only). |
| **Net automated MAIN-LIST qualification** (`CURRENT_OBSERVATION` + `HISTORICAL_VERIFIED` only) | **~5–7 / 15** |
| **`HISTORICAL_ESTIMATE` — informational secondary signal, not on the main list** | **~3–4 / 15** |
| **Remain `UNKNOWN`** | **~3–6 / 15** |

### Where the blind spots are

1. **Fast pump-and-collapse within hours, days before we looked** — minute-candle
   data already thinned by GT, so the price high (hence the estimate) is
   understated. Hourly candles help but can still miss a sharp intra-hour spike.
2. **Heavy locked / team / vesting supply** — `price × total_supply` massively
   overstates the real MC; the concentration check fails, so no estimate; and the
   token isn't on CoinGecko → `UNKNOWN`.
3. **Never discovered** — no activity signal, unguessable name, not trending.
4. **Thin chain coverage** — GT hasn't indexed the pool, or a niche chain.
5. **Genuine MC where circulating ≪ total** — we can only see FDV; risk of both
   false negatives and false positives at the $5M line.

---

## 9. Cost / Rate Limit Comparison

| Provider | Key | Free rate limit | Monthly cap | Historical price | Historical **market cap** | Notes |
|---|---|---|---|---|---|---|
| **DexScreener** | none | ~60–300/min | none documented | ❌ | ❌ | current-state only |
| **GeckoTerminal** | none | **~10–30/min** (site says 10) | none documented | ✅ (day/hour, full life of a ≤ 30-day pool) | ❌ | OHLCV = price + volume only; `market_cap_usd` current-only + CoinGecko-sourced |
| **CoinGecko (keyless)** | none | ~5–15/min, 429s fast | — | ✅ *listed only* | ⚠️ *listed **and** verified supply only* | `/onchain/*`, `/coins/list/new` need a key |
| **CoinGecko (Demo)** | free key | ~30/min (quoted 30–100) | 10k calls | ✅ *listed only* | ⚠️ *listed **and** verified supply only* | 365-day history cap; hourly for 2–90 days |
| **Archive RPC (on-chain)** | varies | free tiers too small | — | ✅ (derivable) | ⚠️ total-supply basis only, per-chain | not MVP-scale |

Budget for **Strategy D** at a 10-minute discovery cycle with ~30–60
age-eligible candidates:

- GT: token (1) + pools (1) + OHLCV paginated (1–3) ≈ **3–5 calls/candidate** →
  ~150–300 GT calls/cycle. At 10–30/min this **must be queued across the cycle**
  with `Retry-After` backoff and per-pool OHLCV caching — not bursted.
- CoinGecko: contract lookup (1) + market_chart (0–1) ≈ **1–2 calls/candidate**
  → ~50–120 CG calls/cycle. Demo key (~30/min, 10k/month) → ~10k–17k/day needed
  if run every 10 min ⇒ **either widen the cycle to ~30–60 min for the CoinGecko
  step, or only call CoinGecko for candidates GT could not verify/estimate.**
- Both providers are free. No paid dependency in the recommended design.

---

## 10. Recommended Architecture

**Strategy D — evidence-tiered, GeckoTerminal as the workhorse, CoinGecko as the
verification layer, our own snapshots always-on.** No archive nodes. No paid
APIs.

Per candidate, **after** the existing age ≤ 30-day gate passes:

1. **Identify & map.** chain + token address (already have). Map
   DexScreener-slug → GT-slug → CoinGecko platform id (static table).
2. **Current check — existing.** If any current snapshot has
   `size_basis = market_cap` and MC ≥ $5M → **`CURRENT_OBSERVATION`**, qualified,
   stop. *(This already covers "launched 2 days ago, sitting at $8M now".)*
3. **CoinGecko verify.** `GET /coins/{platform}/contract/{address}`.
   - 404 → skip to step 4.
   - 200 with a coin id → `GET /coins/{id}/market_chart/range` over
     `[pool_created_at, now]`. If any **non-zero** `market_caps` point ≥ $5M →
     **`HISTORICAL_VERIFIED`**, qualified; store `{value, timestamp, source_url}`.
   - 200 but `market_caps` all zero / missing → treat as not verified, step 4.
4. **GeckoTerminal estimate.**
   - token → pools → pick max `reserve_in_usd`.
   - `GET .../pools/{pool}/ohlcv/hour` paginated (`before_timestamp`) back to
     `pool_created_at`. `peak_price = max(high)`.
   - `GET .../tokens/{address}` → `total_supply`; `.../tokens/{address}/info` →
     `mint_authority` (Solana), `developer_holding_percentage`, holder
     distribution.
   - **Supply-basis check** (all must hold): mint disabled / ownership renounced;
     `developer_holding_percentage` low; no single non-LP / non-burn holder with
     a large share; holder distribution not pathological.
   - If the check passes and `peak_price × total_supply ≥ $5M` →
     **`HISTORICAL_ESTIMATE`** (basis = `fdv`), **NOT qualified for the main
     list** — stored as an informational secondary signal
     (`tokens.historical_estimate_fdv_usd`) with a confidence score and an
     explicit "FDV estimate" label. Else → step 5.
5. **`UNKNOWN`.** Not qualified. Label: *"Not observed at ≥ $5M by this detector,
   and historical market cap could not be verified before `first_observed_at`.
   This is not a claim that it never reached $5M."*
6. **Persist evidence** (`Evidence`-style record, §6) and **re-evaluate every
   cycle** — `UNKNOWN` can become `HISTORICAL_ESTIMATE`/`VERIFIED` later;
   `ESTIMATE` upgrades to `VERIFIED` when CoinGecko finally indexes the token.

### "A token launched 2 days ago already hit $8M before we discovered it — how does the software determine that as fast and reliably as possible?"

- **Still near $8M at first enrichment** → step 2, `CURRENT_OBSERVATION`,
  qualified in the same discovery cycle we discover it. *(Already works today.)*
- **Fell back before we saw it** → step 4: GT `ohlcv/hour` since `pool_created_at`
  gives the full price curve for a 2-day-old pool; `peak_price × total_supply`
  (supply immutable) recovers the ~$8M FDV-basis peak → `HISTORICAL_ESTIMATE`,
  qualified **within one discovery cycle** (~seconds of extra work). CoinGecko
  confirms it as `HISTORICAL_VERIFIED` only if/when it indexes the token *with* a
  circulating-supply basis — not expected at 2 days old.
- **Reliability:** price recovery is reliable (GT has full hourly history for a
  2-day pool). The MC↔FDV gap is the only real risk, bounded by the
  supply-basis check and the "estimate" label.

### "If no provider can verify historical market cap, what should the product display?"

Explicit, non-fabricated labels:

| Label | Copy |
|---|---|
| **Observed peak** | "Observed peak market cap **$X** — highest our detector has recorded since `<first_observed_at>`." (`CURRENT_OBSERVATION`) |
| **Historical verified peak** | "Historical verified peak **$X** (`<date>`, source: CoinGecko)." (`HISTORICAL_VERIFIED`) |
| **Historical estimate** | "Historical estimate **~$X** — FDV basis (peak price × total supply); circulating supply unverified." (`HISTORICAL_ESTIMATE`) — always hedged, always states the basis |
| **Unknown** | "Unknown — not observed at ≥ $5M by this detector, and history before `<first_observed_at>` could not be verified. **This is not a claim that it never reached $5M.**" (`UNKNOWN`) |

Qualification: `HISTORICAL_VERIFIED` or `CURRENT_OBSERVATION` → qualify
unconditionally. `HISTORICAL_ESTIMATE` above a confidence threshold → qualify,
with the estimate label surfaced everywhere the token appears. `UNKNOWN` → not in
the qualified list, not asserted as a failure.

---

## 11. Known Limitations

1. **No free source provides historical market cap for a fresh unlisted
   memecoin.** GT gives price only; CoinGecko 404s; CoinGecko for many *listed*
   memecoins returns an all-zero `market_caps` array.
2. **`HISTORICAL_ESTIMATE` is an FDV-basis figure.** It equals market cap only
   when circulating ≈ total. The supply-basis check reduces but does not
   eliminate false positives/negatives at the $5M line.
3. **GT minute-candle retention is short.** A sharp intra-hour pump days ago may
   be under-captured; hourly `high` is the practical ceiling of what we can
   recover.
4. **GT free rate limit is low (~10–30/min).** The historical step must be
   queued across the discovery cycle, not bursted; may push the effective cycle
   for this step to 30–60 min.
5. **CoinGecko Demo quota (10k/month)** forces "CoinGecko only for candidates GT
   could not resolve", or a slower cadence.
6. **Chain-slug mapping** (DexScreener ↔ GT ↔ CoinGecko) is a static table that
   must be maintained; an unmapped chain silently loses the historical step.
7. **Discovery is still a sample.** A token never surfaced by DexScreener or GT
   feeds is never evaluated at all.
8. **On-chain reconstruction is out of reach** for the MVP (archive RPC + per-
   token locked-supply classification).
9. **Supply immutability is chain-specific to detect.** Clean signal on Solana
   (`mint_authority`); on EVM it needs contract inspection (no `mint`, ownership
   renounced) which GT does not fully expose.
10. **Everything is "best effort at evaluation time".** Labels can change between
    cycles as providers catch up; the product must show the label and its date,
    never a bare number.

---

## 12. Decision

Adopt **Strategy D** as the target design for the historical-qualification
engine (to be built in a later step — **not now**):

> **DexScreener** discovers candidates, gates age, and provides current MC
> (`CURRENT_OBSERVATION`). For any candidate not already qualified on a current
> observation, **CoinGecko** is queried for a *verified* historical market-cap
> point (`HISTORICAL_VERIFIED`); if unavailable, **GeckoTerminal** OHLCV price
> history × immutable total supply, gated by a supply-basis check, produces a
> labelled FDV-basis `HISTORICAL_ESTIMATE`. Anything else is `UNKNOWN` — shown
> as "unknown", never as "never reached $5M". Every determination stores a
> re-evaluable evidence record.

No paid APIs. No archive nodes. No fabricated peaks. No price-high → market-cap
conversion without the supply-basis check.

---

## Final Report

1. **Best free historical source:** **GeckoTerminal OHLCV** — historical *price*,
   full history since pool creation for any token ≤ 30 days old, no API key.
   (CoinGecko is the best source for *verified market cap*, but only for the
   minority of tokens it has properly indexed.)
2. **Does the best source provide historical market cap directly?** **No.**
   GeckoTerminal OHLCV is price + volume only. CoinGecco provides it directly but
   **only** for listed tokens **with a verified circulating supply** — for many
   listed memecoins the historical `market_caps` array is entirely zeros
   (verified live: Pistacio).
3. **Does it work for newly-launched unlisted memecoins?** **GeckoTerminal:
   yes** (price history from pool creation). **CoinGecko: no** — `404 coin not
   found` (verified live).
4. **Best fallback:** our own **`CURRENT_OBSERVATION`** snapshots — keep taking
   them every cycle; accuracy for any given token only improves the longer we
   watch it. On-chain archive lookup stays a manual, per-token last resort.
5. **Recommended MVP strategy:** **Strategy D** — DexScreener (discover + age +
   current MC) → CoinGecko verify → GeckoTerminal price-history estimate →
   evidence-tiered qualification (`HISTORICAL_VERIFIED` / `CURRENT_OBSERVATION` /
   `HISTORICAL_ESTIMATE` / `UNKNOWN`).
6. **Expected coverage (15-coin benchmark):** automated "ever ≥ $5M"
   determination for **~9–12 of 15** — mostly `CURRENT_OBSERVATION` +
   `HISTORICAL_ESTIMATE`, ~1 `HISTORICAL_VERIFIED`; **~3–6 remain `UNKNOWN`**.
7. **Biggest remaining blind spot:** a token that **pumped past $5M and collapsed
   within hours, before our first observation**, where **circulating supply is
   materially below total supply** (team/vesting/locked) so the FDV-basis
   estimate is unsafe **and** CoinGecko never indexed it → genuinely `UNKNOWN`,
   and **no free source recovers it**.

---

## Implementation Decisions (Step 13C — 2026-08-28)

Strategy D is now implemented. What was built and the choices made:

### Where it runs

`DexScreenerDiscoveryService` pipeline, after age filter + observation
persistence:

```
DISCOVER → ENRICH → NORMALIZE → AGE FILTER → PERSIST TOKEN + SNAPSHOT
→ CURRENT OBSERVATION CHECK → HISTORICAL LOOKUP → QUALIFICATION
→ PERSIST EVIDENCE → RETURN
```

The existing discovery / enrichment / normalization code is unchanged.

### Schema

- **`historical_peak_evidences`** — one row per token (`token_id` unique),
  upserted every run, re-evaluable. Columns: `status`, `peak_value_usd`,
  `peak_observed_at`, `evidence_source`, `evidence_basis`, `source_reference`,
  `historical_window_start/end`, `confidence`, `checked_at`, `notes`,
  timestamps. No provider JSON is stored — `source_reference` is a short pointer
  (`coingecko:<coin-id>`, `geckoterminal:pool:<addr>`, `market_snapshots`) and
  `notes` is one line.
- **`tokens.historical_peak_value` / `historical_peak_value_at` /
  `historical_peak_status`** — a denormalized headline of the evidence, so the
  read API can filter/sort without a join. **`observed_peak_market_cap` is
  never written by this engine** — it stays OUR OWN snapshot peak. The two are
  reported as separate API fields.

### Evidence statuses (precedence: VERIFIED > CURRENT_OBSERVATION > ESTIMATE > UNKNOWN)

| Status | Set when | `evidence_basis` | Confidence |
|---|---|---|---|
| `CURRENT_OBSERVATION` | `observed_peak_market_cap >= threshold` (no external call) | `current_market_cap` | high |
| `HISTORICAL_VERIFIED` | CoinGecko `market_caps` has a **non-zero** point `>= threshold` | `market_cap` | high |
| `HISTORICAL_ESTIMATE` | GeckoTerminal `max(hourly high) × normalized_total_supply >= threshold` AND the supply-safety gate passes | `fdv_total_supply` | medium (mint confirmed immutable) / low (opted-in, no signal) |
| `UNKNOWN` | none of the above — never rendered as "did not reach the threshold" | — | — |

A sub-threshold CoinGecko peak or a sub-threshold GT estimate → `UNKNOWN` (with
a note), **not** a qualifying status. No FDV value is ever written into a
`market_cap` basis or into `observed_peak_market_cap`.

### CoinGecko adapter (`App\Services\Historical\CoinGeckoClient`)

- `GET /coins/{platform}/contract/{address}` → 404 returns a `not_found`
  sentinel (cached) → fall through to GeckoTerminal.
- Else `GET /coins/{id}/market_chart/range?from&to` over
  `[earliest_pair_created_at … now]` (floored at now − 30d). Take the max
  **non-zero** `market_caps` point. All-zero array → `no_market_cap` → **not
  verified** (fall through).
- Bounded: `max_calls_per_run` (default 20) HTTP calls per run; responses cached
  `cache_ttl` (default 6 h); one retry on 429; optional
  `COINGECKO_API_KEY` sent as `x-cg-demo-api-key`. Any other failure →
  `unavailable` → fall through. Never throws.

### GeckoTerminal adapter (`App\Services\Historical\GeckoTerminalClient`)

1. `GET /networks/{net}/tokens/{addr}/pools` → **pool with the highest
   `reserve_in_usd`** (deterministic tie-break: smallest pool address). This is
   the token's deepest single market — **not** claimed to cover every DEX.
2. `GET /networks/{net}/pools/{pool}/ohlcv/hour?limit=1000&before_timestamp=now`
   → one page covers a ≤ 30-day pool (≤ 720 candles). `peak_price = max(high)`
   over candles within the window.
3. `GET /networks/{net}/tokens/{addr}` → `normalized_total_supply` (or
   `total_supply / 10^decimals`).
4. `GET /networks/{net}/tokens/{addr}/info` → `mint_authority`.
5. **Supply-safety gate:**
   - `mint_authority` explicitly `null` → *confirmed immutable* → estimate at
     **medium** confidence.
   - `mint_authority` a non-empty string → *mutable* → **reject** (`UNKNOWN`).
   - key absent / info unavailable → *no signal* → **reject** unless
     `HISTORICAL_ESTIMATE_ALLOW_UNVERIFIED_SUPPLY=true`, then estimate at **low**
     confidence.
   - total supply missing / ≤ 0 → **reject** (`UNKNOWN`).
6. `estimate = peak_price × total_supply`. `>= threshold` → `HISTORICAL_ESTIMATE`
   (`evidence_basis = fdv_total_supply`); else `UNKNOWN`.

Bounded by `max_calls_per_run` (default 45); cached `cache_ttl` (6 h); one retry
on 429; never throws.

### Lookup trigger, cooldown, budget

- External lookup happens **only** for a token that is age-eligible, whose
  `observed_peak_market_cap < threshold`, and whose evidence is stale.
- `CURRENT_OBSERVATION` is free (no external call) and re-evaluated every run.
- `HISTORICAL_VERIFIED` is **terminal** (a past peak does not un-happen) — never
  re-checked.
- `HISTORICAL_ESTIMATE` and `UNKNOWN` are re-checked once `checked_at` is older
  than `HISTORICAL_LOOKUP_COOLDOWN_HOURS` (default 6) — so an un-indexed token
  can later become `VERIFIED`.
- `HISTORICAL_MAX_LOOKUPS_PER_RUN` (default 15) caps external lookups per
  discovery run; tokens over the cap keep their existing evidence (or get an
  unchecked `UNKNOWN`) and are prioritized next run (oldest `checked_at` first).
- Lookups run **sequentially** (small volume; safest for the ~10–30 req/min
  free tiers).

### Chain mapping

`config/historical.php` `chain_map`: DexScreener slug → `{ coingecko
asset-platform id, geckoterminal network id }`. Starter set: ethereum, solana,
bsc, base, arbitrum, polygon, avalanche, optimism, pulsechain. An unmapped chain
skips the external lookup (→ `UNKNOWN`, note) — `CURRENT_OBSERVATION` still
works.

### Read API

`GET /api/memecoins` now returns a token qualified by **any** of
`CURRENT_OBSERVATION` / `HISTORICAL_VERIFIED` / `HISTORICAL_ESTIMATE` (peak `>=`
threshold), sorted by `GREATEST(observed_peak_market_cap, historical_peak_value)`
desc. Each row adds `qualification_status`, `qualification_peak_value`,
`qualification_peak_at`, `qualification_source`, `qualification_basis`.
`UNKNOWN` tokens are not returned. Still 2 queries, no N+1
(`with(['latestSnapshot', 'historicalPeakEvidence'])`). The endpoint still never
calls any external provider.

---

## Appendix — Live test log (2026-08-28)

| Call | Observation |
|---|---|
| `GET api.dexscreener.com` docs | no historical / OHLCV / candle / trade / snapshot endpoint exists |
| GT `GET /networks?page=1..` | slugs `eth`,`bsc`,`solana`,`base`,`arbitrum`,`polygon_pos`,… ; 429 after ~6 rapid calls |
| GT `GET /networks/solana/tokens/Ci11…QDx4` (our DOGE) | `market_cap_usd: null`, `fdv_usd 74,461,580`, `total_supply 999,999,999,000,000`, `coingecko_coin_id: null` |
| GT `GET /networks/solana/tokens/DezX…B263` (BONK) | `market_cap_usd 268,108,497` (has `coingecko_coin_id "bonk"`) |
| GT `GET /networks/solana/tokens/Ci11…/pools` | pool `8JWZ…HBFW`, `pool_created_at 2026-08-20T06:07:17Z`, `reserve_in_usd 74,166,514`, `market_cap_usd null` |
| GT `GET .../pools/8JWZ…/ohlcv/day` | 9 candles = full life; columns `[ts,o,h,l,c,v]`, **no MC** |
| GT `GET .../pools/8JWZ…/ohlcv/hour` | 183 candles = full life |
| GT `GET .../ohlcv/minute?before_timestamp=T-3d` | 331 candles, sparse (trade-gated) |
| GT WIF/SOL pool `ohlcv/day?limit=1000` | 181 candles, oldest 2026-03-01 → ~6-month daily cap |
| GT `GET /networks/solana/tokens/Ci11…/info` | `mint_authority: null`, `developer_holding_percentage`, `holders`, `is_honeypot` — no supply |
| GT `GET /networks/solana/new_pools` | 20 pools, seconds old, all `market_cap_usd: null` |
| GT `GET /networks/trending_pools?include=base_token` | 20 pools; ~16 had a `coingecko_coin_id` (survivorship bias); several still `market_cap_usd: null` |
| CG `GET /coins/ethereum/contract/0x6982…1933` (PEPE) | 200, `market_cap.usd 1.59B`, `circulating == total` |
| CG `GET /coins/solana/contract/Ci11…QDx4` (our DOGE) | **404 `{"error":"coin not found"}`** |
| CG `GET /coins/pepe/market_chart?days=30` | `market_caps` 31 points, real values |
| CG `GET /coins/dogwifcoin/market_chart/range` 10d | 240 hourly `market_caps` points |
| CG `GET /coins/pistacio` (listed, 2 days old) | `circulating_supply 0.0`, `market_cap.usd 0.0`, `fdv 3.8M` |
| CG `GET /coins/pistacio/market_chart?days=7` | 54 price points (real), 54 `market_caps` points **all 0** |
| CG keyless | HTTP 429 after ~6 rapid calls; `/onchain/*` + `/coins/list/new` need a Demo key |
