# Memecoin Risk Screening Reconnaissance

**Step 23 — reconnaissance only. No code, no migrations, no frontend, no schedule
changes, no commit.** This document is the input to a later implementation
decision. It describes what risk/safety data is obtainable from **free, official,
documented** APIs, how reliable it is, how it behaves per chain, and how missing
data must be treated.

Live API verification date: **2026-09-01**. All endpoints below were called
directly during this reconnaissance unless marked otherwise.

---

## 1. Goal

The current main list qualifies a token when **all** of:

- pair age ≤ 30 days (`earliest_pair_created_at` = earliest DEX pool creation), and
- a **verified or observed** market-cap peak has ever landed inside `[$5M, $200M]`
  (`CURRENT_OBSERVATION` from our own snapshots, or `HISTORICAL_VERIFIED` from
  CoinGecko), and
- `volume.h24 > 0`, `liquidity.usd > 0`, `marketCap > 0`.

This is a **market-size** filter. It says nothing about whether the token can be
sold, whether the contract can be re-minted or its balances rewritten, whether
one wallet holds 60% of supply, or whether the price chart is a completed
pump-and-dump. The observed universe today contains tokens that are all of those
things.

**Goal of Step 23:** design a *conservative risk screen* layered on top of the
existing market-cap qualification, so the homepage main list shows **lower-risk
qualified memecoins**, and tokens that qualify on market cap but fail important
safety checks are moved to a visible **RISK WATCH** shelf rather than hidden.

Non-goals: this is not a scoring engine build, not a "safe to invest" signal, not
a fraud oracle, not a trading recommendation.

---

## 2. Risk vs Safety

**This is a RISK FILTER, not a "SAFE TO INVEST" guarantee.**

Rules that are non-negotiable for any later implementation:

- The product must **never** display the words **"safe coin"**, **"guaranteed
  safe"**, **"good investment"**, "verified safe", "audited", or any synonym that
  implies an endorsement.
- Allowed vocabulary: **LOWER RISK**, **MEDIUM RISK**, **HIGH RISK**,
  **CRITICAL — AVOID**, **DISQUALIFIED**, **UNKNOWN**.
- A token passing every check we can run is **"no automated red flags found in
  the checks we can perform"** — never "safe".
- Every risk verdict must be **explainable**: each contributing signal, its
  source, its value, its timestamp, and whether it was measured or assumed.
- **Absence of evidence is not evidence of safety.** A token on a chain no
  security provider supports is **UNKNOWN**, and UNKNOWN is a displayed state,
  not a silent pass and not an automatic fail.
- Risk is **point-in-time**. A contract can be re-proxied, an owner can be
  un-renounced via `can_take_back_ownership`, taxes can be changed via
  `slippage_modifiable`. Every risk record carries `checked_at` and must be
  re-evaluable.

---

## 3. Signal Matrix

All 14 user-proposed signals, plus the derived ones. Columns:
**Signal · Provider · Endpoint · Chain coverage · Free? · Current/Historical ·
Reliability · Missing behavior · MVP suitability.**

Reliability legend: **A** = authoritative on-chain read, **B** = provider
heuristic / derived, **C** = best-effort, frequently stale or absent.

| # | Signal | Provider | Endpoint | Chain coverage | Free? | Cur/Hist | Rel. | Missing behavior | MVP suitability |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Contract verified / open-source | GoPlus | `token_security/{cid}` `is_open_source` | EVM only (not in Solana schema) | Yes | Current | A (EVM) | key absent or `""` → UNKNOWN | **High** (EVM); N/A Solana |
| 2 | Proxy contract / upgradeable logic | GoPlus | `token_security/{cid}` `is_proxy` | EVM only | Yes | Current | A | absent/`""` → UNKNOWN | **High** (EVM) |
| 2b | Solana authority mutability | GoPlus / GeckoTerminal | `solana/token_security` `metadata_mutable`, `balance_mutable_authority`, `transfer_hook_upgradable` / `/info` `mint_authority` | Solana | Yes | Current | A | `status` field always present; empty `authority[]` = renounced | **High** (Solana equivalent of "proxy") |
| 3 | Mint authority / mintable supply | GoPlus | EVM `is_mintable`; Solana `mintable.{status,authority}` | EVM + Solana | Yes | Current | A | EVM `is_mintable` may be absent → UNKNOWN; Solana `status` always present | **High** |
| 4 | Owner renounced / owner address | GoPlus | `token_security/{cid}` `owner_address`, `can_take_back_ownership`, `hidden_owner`, `owner_change_balance` | EVM (rich); Solana via authority arrays | Yes | Current | A/B | `owner_address:""` → UNKNOWN (**not** "renounced"); zero/dead address → renounced *for the owner role only* | **High**, with nuance (§8) |
| 5 | Buy tax | GoPlus | `token_security/{cid}` `buy_tax` | EVM only (simulation-based) | Yes | Current | B | absent key or `""` → UNKNOWN (**not 0%**) | **High** (EVM); UNKNOWN Solana |
| 6 | Sell tax | GoPlus | `token_security/{cid}` `sell_tax` | EVM only | Yes | Current | B | absent/`""` → UNKNOWN; `"1"`/`"1.0"` (==100%) → CRITICAL | **High** (EVM) |
| 6b | Transfer tax / transfer pausable | GoPlus | `transfer_tax`, `transfer_pausable` | EVM only | Yes | Current | B | `""` → UNKNOWN | Medium |
| 7 | Honeypot / cannot sell | GoPlus + GeckoTerminal | GoPlus `is_honeypot`, `cannot_sell_all`, `cannot_buy`; GT `/info` `is_honeypot` | EVM (GoPlus sim); Solana partial | Yes | Current | B | `is_honeypot` often absent → UNKNOWN; **never** default to "not a honeypot" | **High** when present; UNKNOWN otherwise |
| 8 | Holder count | GoPlus + GeckoTerminal | GoPlus `holder_count`; GT `/info` `holders.count` | EVM + Solana (both providers) | Yes | Current | B/C | absent → UNKNOWN; providers lag new tokens by minutes–hours | **Medium** (context only, §7) |
| 9 | Top-holder concentration | GoPlus + GeckoTerminal | GoPlus `holders[]` (**top 10 only**), `owner_percent`, `creator_percent`; GT `/info` `holders.distribution_percentage` (top_10 / 11–30 / 31–50 / rest) | EVM + Solana | Yes | Current | B | `holders:[]` empty → UNKNOWN; **must net out** LP / burn / bridge / CEX / lock (§7) | **Medium–High** with exclusions |
| 10 | Creator / dev balance & % | GoPlus | `creator_address`, `creator_balance`, `creator_percent` (EVM); `creators[]`, `dev` (Solana) | EVM + Solana | Yes | Current | B | `creator_percent:""` or `creator_address:""` → UNKNOWN | Medium |
| 11 | LP holder count / LP locked / burned | GoPlus + DexScreener | GoPlus `lp_holders[]` (top 10, each `is_locked`, `tag`, `percent`), `lp_holder_count`, `lp_total_supply`; DexScreener `liquidity.usd` per pair | EVM (GoPlus LP fields); Solana `lp_holders` sparse | Yes | Current | B/C | GoPlus `lp_holders:[]` → UNKNOWN; `is_locked` only flags **known** lockers → not-locked ≠ "not locked" | Medium (§9) |
| 12 | Blacklist / whitelist / trading switch | GoPlus | `is_blacklisted`, `is_whitelisted`, `transfer_pausable`, `trading_cooldown`, `is_anti_whale`, `anti_whale_modifiable` | EVM only | Yes | Current | B | `""` → UNKNOWN | Medium |
| 13 | Liquidity vs volume ratio | DexScreener (+ our snapshots) | `/token-pairs/v1` / `/tokens/v1` `volume.h24`, `liquidity.usd` per pair | All DexScreener chains | Yes | Current + our history | A (raw), B (ratio heuristic) | either 0/absent → do not compute ratio; treat as thin-data | **High** (heuristic bands, §9) |
| 14 | Buy/sell transaction balance | DexScreener (+ our snapshots) | `/token-pairs/v1` `txns.h24.{buys,sells}`, our `buys_h24`/`sells_h24` | All DexScreener chains | Yes | Current + our history | A (raw counts) | both 0 → UNKNOWN; **sells > buys is not scam proof** (§10) | **Medium** (context) |
| D1 | Chart shape — pump-then-crash | **Our own** `market_snapshots` + `pump_events`; GeckoTerminal OHLCV as fallback for fresh tokens | internal DB; GT `/networks/{net}/pools/{pool}/ohlcv/hour` | any chain we snapshot; GT for its supported nets | Yes | **Historical (ours)** | B | < ~6 snapshots and no GT pool → "insufficient history", not "no pump-dump" | **High** (numeric only, §10) |
| D2 | Community takeover context | DexScreener | `/community-takeovers/latest/v1` | DexScreener chains | Yes | Current list (rolling) | C | not in list → no signal (neutral), never a negative | Low (context tag only) |
| D3 | Rugpull risk composite (EVM) | GoPlus | `rugpull_detecting/{cid}` | **EVM only** | Yes | Current | B | 404 / empty → UNKNOWN | Medium (EVM supplement) |
| X1 | Top-trader "Bought / Sold" per wallet | — | — | — | — | — | — | **NOT_AVAILABLE from any free official API** | **None** (§6) |

---

## 4. DexScreener Data

Confirmed against `docs/dexscreener-reconnaissance.md` and re-verified live.

**What DexScreener gives us (all current-state, no key, no historical):**

- Per pair: `priceUsd`, `priceNative`, `liquidity.{usd,base,quote}`,
  `volume.{m5,h1,h6,h24}`, `priceChange.{m5,h1,h6,h24}`,
  `txns.{m5,h1,h6,h24}.{buys,sells}`, `fdv`, `marketCap`, `pairCreatedAt`,
  `dexId`, `labels`, `baseToken`, `quoteToken`, `info.{websites,socials,imageUrl}`,
  `boosts.active`.
- Multi-pool visibility: `/token-pairs/v1/{chain}/{addr}` and
  `/tokens/v1/{chain}/{addr}` return **every** indexed pair for the token → we
  can see how many pools / DEXes / quote assets exist and each pool's depth.
- `/community-takeovers/latest/v1` → rolling list of `{url, chainId,
  tokenAddress, icon, header, description, links[], claimDate}`.

**What DexScreener does NOT provide (verified — mark
`UNAVAILABLE_FROM_OFFICIAL_API`):**

- ❌ Any contract-security scan (proxy, mint, tax, honeypot, owner, blacklist).
- ❌ Holder count or holder list.
- ❌ LP holder list, LP lock status, LP burn status.
- ❌ Top-trader / per-wallet bought-sold figures (the DexScreener web UI's
  "Top Traders" tab is **not** in the public API).
- ❌ OHLCV / candlestick / trade history endpoints.
- ❌ "List all pairs on a chain".
- ❌ Any `X-RateLimit-*` headers (rate limits are documented per-group only:
  ~300/min for the pairs/tokens/search group, ~60/min for
  profiles/boosts/metas/orders/community-takeovers; responses carry
  `Cache-Control: max-age=60`).

The DexScreener price-chart `<iframe>` on the detail page (CLAUDE.md Step 17
exception) is a **visual embed only** and stays that way. No JS call to
`api.dexscreener.com` beyond the already-documented server-side discovery/enrich
path.

**Chart-shape from DexScreener:** only the coarse `priceChange.{h1,h6,h24}` +
`volume` deltas are available. Enough to notice "down 70% in 24h" but not to
reconstruct a shape. Our own snapshots (§10) are the better source.

---

## 5. GoPlus Data

**Verdict: GoPlus Security API is the best available free security provider.**
Widest field set, EVM + Solana + partial Robinhood, no key required (an optional
free App Key raises rate limits), batchable, and it held up to 8+ rapid
back-to-back calls during recon without throttling.

### Endpoints (all `https://api.gopluslabs.io/api/v1/...`, GET, no key)

| Endpoint | Scope | Notes |
|---|---|---|
| `token_security/{chain_id}?contract_addresses=a,b,c` | EVM chains | comma-batch; `chain_id` is the numeric EVM id (`1`, `56`, `8453`, `42161`, `137`, `43114`, `10`, …) |
| `solana/token_security?contract_addresses=...` | Solana | **different response schema** (see below) |
| `rugpull_detecting/{chain_id}?contract_addresses=...` | **EVM only** | composite rug heuristics; 404/empty common on new tokens |
| `supported_chains` | — | authoritative list of chains `token_security` covers |
| `token_security/4663?...` | Robinhood chain | **partial** — many Robinhood tokens absent from the DB |

Batch limit: keep to ~20–30 addresses/call in practice. Rate: documented
~30/min unauthenticated; higher with a free App Key. No `X-RateLimit` headers.

### EVM `token_security` — fields relevant to risk (live-verified on PEPE, CAKE, BRETT)

**Contract security**

| Field | Meaning | Good value | Bad value | Missing → |
|---|---|---|---|---|
| `is_open_source` | source verified | `"1"` | `"0"` | `""`/absent = UNKNOWN |
| `is_proxy` | upgradeable proxy | `"0"` | `"1"` | `""` = UNKNOWN |
| `is_mintable` | supply can be minted | `"0"` | `"1"` | absent = UNKNOWN → **treat as HIGH RISK** (§8) |
| `owner_address` | current owner | dead/zero addr | live EOA/multisig | `""` = UNKNOWN, **not** renounced |
| `can_take_back_ownership` | renounce is reversible | `"0"` | `"1"` | `""` = UNKNOWN |
| `hidden_owner` | owner obfuscated | `"0"` | `"1"` | `""` = UNKNOWN |
| `owner_change_balance` | owner can edit balances | `"0"` | `"1"` | `""` = UNKNOWN |
| `selfdestruct` | contract can self-destruct | `"0"` | `"1"` | `""` = UNKNOWN |
| `external_call` | calls arbitrary external contracts | `"0"` | `"1"` | `""` = UNKNOWN |

**Trading / tax (EVM simulation-based)**

| Field | Meaning | Notes |
|---|---|---|
| `buy_tax` | decimal string, `"0.05"` = 5% | `""`/absent = UNKNOWN, never 0% |
| `sell_tax` | decimal string | `"1"` = 100% = CRITICAL (cannot exit) |
| `transfer_tax` | decimal string | `""` = UNKNOWN |
| `cannot_buy` | `"1"` = buys blocked | |
| `cannot_sell_all` | `"1"` = cannot sell entire balance | |
| `slippage_modifiable` | tax can be changed later | `"1"` = tax is not fixed |
| `is_honeypot` | simulated honeypot | **often absent** → UNKNOWN |
| `transfer_pausable` | transfers can be frozen | |
| `is_blacklisted` / `is_whitelisted` | address gating exists | |
| `is_anti_whale` / `anti_whale_modifiable` | max-wallet caps, mutable | |
| `trading_cooldown` | per-wallet cooldown | |
| `personal_slippage_modifiable` | per-address tax | |

**Distribution**

| Field | Meaning | Notes |
|---|---|---|
| `holder_count` | total holders | string int; lags new tokens |
| `total_supply` | total supply | |
| `holders[]` | **top 10 only**, each `{address, tag, is_contract, is_locked, balance, percent}` | `tag` frequently `""` |
| `owner_balance` / `owner_percent` | owner's holding | |
| `creator_address` / `creator_balance` / `creator_percent` | deployer's holding | |
| `lp_holders[]` | **top 10 LP holders**, each `{address, tag, is_contract, is_locked, balance, percent}` | `is_locked=1` only for **known** lockers/burn |
| `lp_holder_count` / `lp_total_supply` | LP token spread | |
| `is_in_dex` | has a live DEX pair | |

**Meta flags**

- `is_true_token`, `is_airdrop_scam`, `fake_token`, `trust_list`,
  `other_potential_risks`, `note`. `trust_list` / `fake_token` are usually
  `null`; only act on positive values.

### Solana `solana/token_security` — **different schema** (live-verified on BONK)

Keys returned: `mintable`, `freezable`, `closable`, `non_transferable`,
`balance_mutable_authority`, `metadata_mutable`, `default_account_state`,
`default_account_state_upgradable`, `transfer_fee`, `transfer_fee_upgradable`,
`transfer_hook`, `transfer_hook_upgradable`, `holder_count`, `total_supply`,
`holders[]`, `lp_holders[]`, `creators[]`, `dex[]`, `metadata`,
`trusted_token`.

| Field | Shape | Risk reading |
|---|---|---|
| `mintable` | `{status:"0"|"1", authority:[...]}` | `status:"1"` or non-empty `authority` = supply not fixed → **HIGH RISK** |
| `freezable` | `{status, authority}` | `status:"1"` = accounts can be frozen (cannot sell) → HIGH RISK |
| `balance_mutable_authority` | `{status, authority}` | `status:"1"` = balances can be rewritten → CRITICAL |
| `metadata_mutable` | `{status, metadata_upgrade_authority:[{address, malicious_address}]}` | mutable metadata alone = LOW/MEDIUM; `malicious_address:1` = HIGH |
| `non_transferable` | `{status}` | `"1"` = soulbound, cannot trade → CRITICAL |
| `transfer_fee` | `{}` or `{fee, ...}` | non-empty = Solana "tax" equivalent |
| `transfer_hook` | `[]` or `[...]` | non-empty = arbitrary logic on transfer → HIGH |
| `closable` | `{status, authority}` | mint account can be closed |
| `holder_count`, `holders[]` | int + top 10 | same caveats as EVM |
| `creators[]`, `dev` | deployer info | `dev` was `null` for BONK; often sparse |
| `trusted_token` | `0`/`1` | `1` = GoPlus allow-list (blue chip); `0` = neutral, **not** negative |

**Solana has no `buy_tax`/`sell_tax`/`is_open_source`/`is_proxy` keys** — the
EVM notions do not exist. The equivalent risk axis is the **authority set**
(mint/freeze/balance/close). Empty `authority[]` **and** `status:"0"` = that
power is renounced.

### GoPlus known gaps

- Wrapped-native tokens (WBNB, WETH) can be **absent** from `token_security`
  (verified: a BSC batch for CAKE+WBNB returned only CAKE). Not a risk signal —
  just missing data.
- Invalid address → `code:7013 "Address format error!"` (Solana). Handle as a
  request error, not a token verdict.
- Brand-new tokens (< a few hours) are frequently **not yet indexed** → UNKNOWN,
  re-check later.
- `rugpull_detecting` is EVM-only and 404s often for memecoins.

---

## 6. Top-Trader Data

**Status: NOT_AVAILABLE from any free, official, documented API.**

- The DexScreener web UI "Top Traders" tab (per-wallet "Bought: -- / Sold:
  $600K / PnL") is **not** exposed by `api.dexscreener.com`. Using it would
  require the undocumented internal endpoint or HTML/WebSocket scraping — all
  **explicitly out of scope**.
- GoPlus, GeckoTerminal, CoinGecko: none expose per-wallet trade ledgers.
- Reconstructing it ourselves needs a full per-pool swap indexer (Bitquery /
  Dune / a self-hosted archive node + log decoding) — **out of scope for MVP**
  and arguably out of scope for the product.

**Rule (verbatim from the brief):** a UI line like `Bought: --` / `Sold: $600K`
must **never** be used to infer insider allocation, dev dumping, or sniper
activity without corroborating **on-chain** evidence. In the risk model this
entire dimension is recorded as `top_trader_analysis: NOT_AVAILABLE` and
contributes **zero** to the score (not a penalty, not a bonus).

What we *can* say about "early wallet" behaviour, and only weakly:

- GoPlus `creator_percent` / `owner_percent` / top-10 `holders[]` give a
  **static snapshot** of concentration (not flow).
- Our own `market_snapshots` `buys_h24` / `sells_h24` give **aggregate** flow
  direction, never per wallet.

---

## 7. Holder Analysis

### 7.1 Holder count — NOT a universal hard rule

Per the brief: do **not** make `holder_count >= 1000` a blanket gate. A
legitimately fresh token that peaked at $6M two hours ago may have 300–600
holders. Instead:

- **Holders per $1M market cap** = `holder_count / (market_cap / 1e6)`.
  Observed rough reference points (not thresholds — to be tuned against our own
  qualified sample): blue-chip memecoins sit in the hundreds–thousands per $1M;
  a $10M token with 40 holders total (4 per $1M) is a strong concentration flag.
- **Age-adjusted**: expected holder growth is steep in the first 30 days.
  Compare a token's `holders per $1M` against the **percentile** of the
  currently-qualified cohort **of similar age** (we already store
  `earliest_pair_created_at`, `first_observed_at`, and a snapshot series).
- Missing `holder_count` → `holder_count: UNKNOWN` → contributes nothing, does
  **not** fail the token.

### 7.2 Holder concentration — with mandatory exclusions

Raw "top holder owns 45%" is misleading. Before scoring, **classify and net
out** each of the top-10 entries:

| Exclude / reclassify | How to detect (free) |
|---|---|
| Burn / dead address | address in `{0x0, 0xdead, 11111111...11111 (Solana incinerator)}` |
| LP / pair contract | GoPlus `is_contract:1` **and** address matches a known pool address from DexScreener `/token-pairs/v1`; GoPlus sometimes tags `tag:"UniswapV2"` etc. |
| Locked liquidity locker | GoPlus `holders[].is_locked:1` or `tag` ∈ {Unicrypt, Team Finance, PinkLock, …} |
| Known CEX wallet | GoPlus `tag` (e.g. "Binance"); maintain a small static allow-list |
| Bridge / wrapped custody | GoPlus `tag`; static list (Wormhole, LayerZero, Portal) |
| Staking / vesting contract | `is_contract:1` + `tag` |

**Effective concentration** = sum of top-10 `percent` **after** removing the
above. Signals to derive:

- `top1_effective_pct`, `top5_effective_pct`, `top10_effective_pct`
- `creator_effective_pct` (GoPlus `creator_percent`, if creator is not itself
  an excluded contract)
- GeckoTerminal `/info` `holders.distribution_percentage.top_10` as a
  **cross-check** (it is provider-computed and does not exclude LP — use only to
  confirm the order of magnitude, not as the primary number).

Caveats:

- GoPlus and GeckoTerminal both return **top 10 only** — we cannot compute a
  true Gini/Nakamoto coefficient, only a top-N picture.
- `holders:[]` empty (common for < 1h tokens) → `concentration: UNKNOWN`.
- On Solana, the top holder is very often the AMM pool itself — failing to
  exclude it would false-flag almost every healthy Solana memecoin.

### 7.3 Provider comparison for holder data

| | GoPlus | GeckoTerminal `/info` |
|---|---|---|
| Holder count | `holder_count` (EVM + Solana) | `holders.count` |
| Distribution | top-10 list with per-address % + tags + `is_locked` | bucketed % (top_10 / 11–30 / 31–50 / rest) — **no addresses** |
| LP exclusion | possible (addresses + tags) | not possible (no addresses) |
| Freshness | lags minutes–hours on new tokens | similar lag; sometimes fresher on Solana |
| Best use | **primary** — addresses let us exclude LP/burn | **cross-check** magnitude + Solana authorities |

---

## 8. Contract Security

Document **ownership**, **proxy/upgradeability**, and **implementation
mutability** as **separate** signals — one being "safe" does not cover the
others.

### 8.1 Ownership (the `owner` role)

- `owner_address` = dead/zero → **owner role renounced**. This removes
  owner-gated functions (often: setting tax, pausing, blacklisting).
- `owner_address:""` → **UNKNOWN**, never "renounced".
- `owner_address` = live EOA → owner-gated powers are active. Not automatically
  bad (many legit tokens keep an owner briefly), but **elevated** while
  combined with `slippage_modifiable`, `transfer_pausable`, `is_blacklisted`,
  `is_mintable`.
- `can_take_back_ownership:"1"` → renouncement is **reversible** → treat a
  renounced owner as only weakly protective.
- `hidden_owner:"1"` → owner obscured behind another contract → **HIGH RISK**.
- **Zero address is not universally sufficient**: it clears the `owner` role
  only. A renounced owner on a **proxy** contract is meaningless if the proxy
  admin (a different role) can still swap the implementation.

### 8.2 Proxy / upgradeability

- EVM `is_proxy:"1"` → the logic contract can be replaced → **all other
  security readings are provisional** (taxes, mint, transfer rules can all
  change on the next upgrade). Treat as **HIGH RISK** unless the proxy admin is
  itself provably renounced/timelocked (GoPlus does not reliably tell us this →
  usually stays HIGH RISK for MVP).
- `is_proxy:"0"` → immutable logic → strong positive.
- `is_proxy:""` / absent → **UNKNOWN**.
- Solana equivalent: `metadata_mutable`, `balance_mutable_authority`,
  `transfer_hook_upgradable`, `transfer_fee_upgradable`,
  `default_account_state_upgradable` — any `status:"1"` with a live authority =
  "the rules can change" = the Solana analogue of an upgradeable proxy.

### 8.3 Supply mutability (mint)

- **Default recommendation: `is_mintable:"1"` (EVM) or Solana `mintable.status
  != "0"` / non-empty `mintable.authority` → HIGH RISK**, unless there is a
  clear, documented justification (e.g. a known, capped, timelocked emission
  contract — which we generally cannot verify for free → stays HIGH RISK for
  MVP).
- `is_mintable:""` / absent → **UNKNOWN** → for mint specifically, the brief
  says default to **HIGH RISK** (this is the one signal where UNKNOWN leans
  negative, because an un-renounced mint is the single most common memecoin rug
  vector and its absence in the response usually means "not analysed" on a very
  fresh contract).

### 8.4 Sell-side / exit safety

- `sell_tax == 1` (100%) or `cannot_sell_all:"1"` or `is_honeypot:"1"` or
  Solana `freezable.status:"1"` / `non_transferable.status:"1"` →
  **CRITICAL — AVOID** (cannot exit the position).
- `sell_tax` in `(0.10, 1.0)` → **HIGH RISK**.
- `slippage_modifiable:"1"` / `personal_slippage_modifiable:"1"` → tax is not
  fixed → **HIGH RISK** even if current tax is 0.

### 8.5 Tax bands (proposed, **not locked**)

| Band | buy or sell tax | Class |
|---|---|---|
| 0–2% | normal | neutral |
| 2–5% | elevated | low penalty |
| 5–10% | high | medium penalty |
| 10–50% | punitive | HIGH RISK |
| > 50% | extractive | HIGH RISK |
| 100% / `cannot_sell_all` / honeypot | trap | **CRITICAL — AVOID** |
| `""` / absent | **UNKNOWN** | no penalty, shown as UNKNOWN (except mint, §8.3) |

Solana: no percentage tax fields; use `transfer_fee` (non-empty = fee token)
and `transfer_hook` (non-empty = arbitrary transfer logic → HIGH RISK).

---

## 9. Liquidity Risk

### 9.1 One thin pool vs multi-pool

**Multiple pools is NOT automatically safe** (per the brief). But the shape of
liquidity matters:

- `pool_count` (distinct DexScreener pairs with `liquidity.usd > 0`)
- `dex_count` (distinct `dexId`)
- `largest_pool_share` = biggest pool's `liquidity.usd` / total
- `total_liquidity_usd`
- `single_pool` = `pool_count == 1`

Readings:

- **One pool, thin** (`single_pool` and `total_liquidity_usd` small relative to
  market cap) → **HIGH liquidity risk** (a single LP pull ends the token).
- **One pool, deep** → medium (still a single point of failure).
- **Many pools but 98% in one** → effectively single-pool → do not credit the
  pool count.
- **Genuinely spread across ≥ 2 DEXes** → lower liquidity risk, still not
  "safe".

### 9.2 Liquidity vs volume ratio (heuristic bands, **not proof**)

`vol_liq_ratio` = `volume.h24 / liquidity.usd`.

| Band | Reading |
|---|---|
| < 2× | calm / organic-plausible |
| 2–5× | active |
| 5–10× | hot — watch |
| > 10× | very high turnover vs depth — **wash-trading or a blow-off is plausible**, not proven |

Explicitly: **do not treat `10x = scam`.** High ratio + a completed
pump-then-crash shape (§10) + concentrated holders (§7) together are a strong
RISK WATCH case; the ratio alone is a soft signal.

### 9.3 LP lock / burn

- GoPlus `lp_holders[]` (EVM, top 10): `is_locked:1` and/or a `tag` naming a
  known locker (Unicrypt, Team Finance, PinkLock) or a burn address →
  **locked/burned portion** = sum of those `percent`.
- `is_locked:0` on all entries → **UNKNOWN**, not "unlocked" (GoPlus only flags
  lockers it recognises).
- `lp_holder_count == 1` and that holder is an EOA → **HIGH RISK** (LP fully
  withdrawable by one wallet).
- DexScreener gives **no** lock data → GoPlus is the only free source, EVM only,
  and only partial.
- Solana `lp_holders` is sparse/often empty → LP-lock signal is usually
  **UNKNOWN on Solana**.

---

## 10. Pump-Dump Risk

**A pump-then-crash chart contributes to `pump_dump_risk`, not to a "scam"
label.** Many honest memecoins pump and retrace.

### 10.1 Feasibility — confirmed from our own data

Verified live during recon (token SHRUB, Robinhood chain):

- 38 `market_snapshots` at ~10-min cadence over ~5 hours.
- Market cap $16.4M → peak $21.8M → last $13.2M = **39.4% peak-to-current
  drawdown, computed numerically** from stored `market_cap` values.
- **81 `pump_events`** exist across the tracked universe (sample:
  `detection_score=100`, `market_cap_change_pct=163`, `duration_minutes=80`).
- Qualified tokens currently hold **6–45 snapshots** each.

So chart-shape risk is computable **without any image/vision and without a new
provider**, from:

1. `market_snapshots` (our ~10-min series) — primary.
2. `pump_events` (Step 16A, already deterministic) — a detected pump is a
   ready-made input.
3. GeckoTerminal `/networks/{net}/pools/{pool}/ohlcv/hour` — **fallback for
   fresh tokens** where we have < ~6 snapshots (GT returns full hourly history
   for pools ≤ 30 days old; verified SHRUB has GT pools on the `robinhood`
   network). Price + volume only, no market cap — acceptable for *shape*.

### 10.2 Numeric shape signals (proposed)

From the snapshot/OHLCV series over the token's observed life:

| Signal | Definition |
|---|---|
| `peak_to_current_drawdown_pct` | `(peak_mc − latest_mc) / peak_mc` |
| `max_runup_pct` | largest low→high rise within any rolling 6h window |
| `max_drawdown_pct` | largest high→low fall within any rolling 6h window |
| `time_since_peak_hours` | now − `observed_peak_market_cap_at` |
| `round_trip` | `max_runup_pct >= X` **and** subsequent `drawdown >= Y%` of that run-up within `Z` hours |
| `has_completed_pump_event` | a `pump_events` row with `status="completed"` and a large `market_cap_change_pct` followed by retrace |
| `volume_collapse` | `volume.h24` now vs at peak (e.g. < 20% of peak volume) |

`round_trip` + `volume_collapse` + `time_since_peak` small → **classic
pump-and-dump shape** → strong `pump_dump_risk` → RISK WATCH (not hidden,
because the token genuinely did qualify and users may want to see the aftermath).

### 10.3 Missing-history rule

< ~6 snapshots **and** no GeckoTerminal pool → `pump_dump_risk: INSUFFICIENT_HISTORY`
→ contributes nothing. Never render "no pump-and-dump detected" for a token we
have barely observed — say **"insufficient observation history"** (consistent
with the existing cold-start rule in CLAUDE.md / Sprint 1 §6).

### 10.4 Community takeover as context

`/community-takeovers/latest/v1` membership → attach a neutral
`community_takeover: true` context tag (the original team abandoned it and the
community relaunched). It is **context**, slightly risk-relevant (governance
discontinuity) but **not** a negative on its own.

---

## 11. Missing Data

**The single most important rule in this document.** Every signal is
**tri-state**, and the three states are distinguishable in the raw response:

| Raw response | State | Meaning |
|---|---|---|
| key present, meaningful value (`"0"`, `"1"`, `"0.03"`, a number, a list) | **MEASURED** | use it |
| key present, empty string `""` or empty list `[]` or `null` | **UNKNOWN** | provider analysed but has no answer |
| key absent entirely | **UNKNOWN** | provider did not analyse this field |

Rules:

1. `owner_renounced = UNKNOWN` means **UNKNOWN**, never "NO / not renounced".
2. `sell_tax = ""` means **UNKNOWN**, never "0%".
3. A token on a chain **no security provider supports** is **UNKNOWN across the
   whole contract-security dimension** — it must **not** be pushed to HIGH RISK
   purely for that. It is shown with an explicit "contract security could not be
   checked on this chain" note.
4. UNKNOWN signals contribute **0** to the numeric risk score and are listed
   explicitly in the UI breakdown ("N of M checks could not be performed").
5. **One exception, per the brief:** `is_mintable` UNKNOWN → lean **HIGH RISK**
   for the mint sub-signal (§8.3), because an unanalysed mint field on a fresh
   contract is materially more likely to be dangerous than safe, and mint is the
   top rug vector. This exception is **documented and narrow** — it does not
   generalise to other UNKNOWN fields.
6. A verdict computed from mostly-UNKNOWN inputs must itself be labelled
   **"RISK UNKNOWN — insufficient data"**, distinct from "LOWER RISK".
7. `top_trader_analysis` is permanently `NOT_AVAILABLE` (§6) — a distinct label
   from UNKNOWN (we will never have it), and it contributes 0.

### Data-completeness score

Alongside the risk score, compute `data_completeness` = measured signals /
applicable signals. The UI should show it ("risk assessed from 11 of 18
possible checks"). A token can be **DISQUALIFIED** from the main list for **low
data completeness** (e.g. < 50%) even with no positive red flag — it moves to
RISK WATCH labelled "RISK UNKNOWN", not to the main list.

---

## 12. Chain Coverage

| Chain | DexScreener | GoPlus `token_security` | GoPlus `rugpull_detecting` | GeckoTerminal `/info` | Contract-security verdict quality |
|---|---|---|---|---|---|
| **Solana** | ✅ | ✅ `solana/token_security` (authority-model schema) | ❌ (EVM only) | ✅ (`solana` net; mint/freeze authority, honeypot, holders) | **Good** — authority model + GT cross-check |
| **Ethereum** | ✅ | ✅ `chain_id 1` | ✅ | ✅ (`eth`) | **Best** — full EVM field set |
| **BSC** | ✅ | ✅ `56` | ✅ | ✅ (`bsc`) | **Best** |
| **Base** | ✅ | ✅ `8453` | ✅ | ✅ (`base`) | **Best** |
| **Arbitrum** | ✅ | ✅ `42161` | ✅ | ✅ (`arbitrum`) | **Best** |
| **Polygon** | ✅ | ✅ `137` | ✅ | ✅ (`polygon_pos`) | **Best** |
| **Avalanche** | ✅ | ✅ `43114` | ✅ | ✅ (`avax`) | **Best** |
| **Tron** | ✅ (some) | ⚠️ check `supported_chains` (TRON id `tron`/numeric) — historically supported, verify at impl time | ❌ | ⚠️ partial | **Medium** — verify GoPlus coverage before relying |
| **Robinhood** (chain id `4663`) | ✅ | ⚠️ **partial** (`token_security/4663` exists; many tokens absent) | ❌ | ✅ (`robinhood` net — verified SHRUB) | **Low–Medium** — often UNKNOWN; GT `/info` is the fallback |
| **Other / long-tail EVM** | ✅ if indexed | ✅ **iff** in `supported_chains` | varies | ✅ if GT indexes the net | **Varies** — resolve per chain via `supported_chains`, else UNKNOWN |

Implementation notes:

- Maintain one canonical chain map: `dexscreener_slug ↔ goplus_chain_id ↔
  geckoterminal_network ↔ coingecko_platform`. Extend the existing
  `config/historical.php` `chain_map` (already covers ethereum, solana, bsc,
  base, arbitrum, polygon, avalanche, optimism, pulsechain).
- On startup / periodically, fetch GoPlus `supported_chains` and cache it;
  a chain not in that list ⇒ **contract-security = UNKNOWN**, never a fail.
- GeckoTerminal network slugs are **not** the DexScreener slugs (`eth` vs
  `ethereum`, `avax` vs `avalanche`, `polygon_pos` vs `polygon`) — the map must
  encode both.

---

## 13. Proposed Risk Score

**Do NOT implement yet.** Design proposal only.

### 13.1 Shape

- Range **0–100**, **higher = more risky**.
- It is **NOT** "probability of scam" and must never be labelled as such.
- It is a **transparent weighted sum of sub-signals**, each of which is
  independently displayable with its source and value.
- Every sub-signal is MEASURED / UNKNOWN / NOT_AVAILABLE. UNKNOWN and
  NOT_AVAILABLE contribute **0** (except the mint exception, §8.3).
- Alongside it: `data_completeness` (§11) and a `risk_level` band.

### 13.2 Dimensions & weights (proposed; brief's starting weights in brackets)

| Dimension | Weight | Sub-signals |
|---|---|---|
| **Contract security** | **30%** (brief 25) | proxy/upgradeable, mintable, owner not renounced / reversible / hidden, selfdestruct, external_call, Solana authorities (mint/freeze/balance/close/hook) |
| **Exit safety (tax/honeypot)** | **15%** (new — carved from contract) | buy/sell/transfer tax bands, honeypot, cannot_sell_all, slippage_modifiable, transfer_pausable, blacklist |
| **Holder distribution** | **18%** (brief 20) | top1/top5/top10 effective %, creator %, holders per $1M, age-percentile |
| **Liquidity risk** | **12%** (brief 15) | single-pool, largest_pool_share, total liquidity vs MC, LP lock/burn %, LP holder count |
| **Pump-dump shape** | **12%** (brief 15) | round_trip, peak_to_current_drawdown, volume_collapse, completed pump_event, vol/liq ratio band |
| **Market structure** | **8%** (brief 15) | vol/liq ratio (raw), buy/sell balance, txn count sanity, MC vs FDV gap |
| **Age** | **5%** (brief 10) | pool age vs configurable thresholds (soft) |

Rationale for the deltas: contract security + exit safety are the signals that
most directly determine whether a holder can lose everything irreversibly, so
they carry the most weight (combined 45%). Age is weak on its own (the brief
says don't auto-remove young coins) so it drops to 5%. Market structure overlaps
heavily with pump-dump shape, so it shrinks to avoid double-counting.

These weights are **starting points for tuning against our own qualified
sample** — the implementation step should backtest them.

### 13.3 Risk levels

| Level | Score | Also triggered by (hard overrides) |
|---|---|---|
| **LOWER RISK** | 0–24 | — (and `data_completeness ≥ 0.5`) |
| **MEDIUM RISK** | 25–49 | — |
| **HIGH RISK** | 50–74 | any: unrenounced mint, proxy w/ live admin, sell tax 10–99%, single-EOA LP, effective top1 > ~35% |
| **CRITICAL — AVOID** | 75–100 | any: honeypot, 100% sell tax, cannot_sell_all, Solana freezable/non_transferable/balance-mutable, hidden_owner |
| **RISK UNKNOWN** | n/a | `data_completeness < 0.5` — overrides the numeric band for display |

### 13.4 Hard overrides (a single signal forces the level regardless of score)

CRITICAL: `is_honeypot`, `sell_tax >= 1`, `cannot_sell_all`, Solana
`freezable/non_transferable/balance_mutable_authority` active, `is_airdrop_scam`,
GoPlus `fake_token`/negative `trust_list`.

HIGH: unrenounced/absent mint, `is_proxy` with non-renounced admin,
`hidden_owner`, `can_take_back_ownership` + live owner + mutable tax,
single-EOA LP holder.

---

## 14. Proposed Filter

### 14.1 MAIN LIST (strict but realistic MVP)

A token appears on the homepage main list only if **all** of:

**A. Existing market-cap qualification (unchanged)** — age ≤ 30d AND
verified/observed peak MC in `[$5M, $200M]` (`CURRENT_OBSERVATION` or
`HISTORICAL_VERIFIED`; `HISTORICAL_ESTIMATE`/`UNKNOWN` still never qualify).

**B. No CRITICAL override** — not a honeypot, sell tax < 100%, sellable, no
active Solana freeze/balance-mutate/non-transfer authority, not flagged
`fake_token`/`is_airdrop_scam`.

**C. No HIGH-severity contract flag** —
- mint: `is_mintable == "0"` (EVM) / Solana `mintable` fully renounced —
  **measured, not UNKNOWN** (mint UNKNOWN ⇒ RISK WATCH, not main);
- not an upgradeable proxy with a live admin (EVM `is_proxy == "0"`, or proxy
  with provably renounced admin);
- `hidden_owner == "0"`;
- sell tax ≤ ~10% **and** measured (not `""`);
- `slippage_modifiable == "0"` (tax is fixed) — or tax is measured 0 and owner
  renounced.

**D. Liquidity not a single point of failure** —
- LP either has a locked/burned majority (GoPlus `is_locked`/burn ≥ ~50% of LP),
  **or** liquidity is spread across ≥ 2 real pools/DEXes;
- not `lp_holder_count == 1` with an EOA holder.

**E. Holder distribution not extreme** —
- effective top-1 (LP/burn/CEX/bridge excluded) below a configurable ceiling
  (proposed ~25–35%);
- creator effective % below a configurable ceiling (proposed ~15–20%);
- these checks **skipped (not failed)** if `holders:[]` is UNKNOWN — but a token
  with UNKNOWN holders **and** UNKNOWN LP lock goes to RISK WATCH.

**F. Data completeness ≥ configurable minimum** (proposed 0.5). Below that →
RISK WATCH labelled "RISK UNKNOWN".

**G. Not a completed pump-and-dump right now** — not (`round_trip` true AND
`peak_to_current_drawdown` > ~70% AND `volume_collapse` true). Such a token goes
to RISK WATCH, not hidden.

**H. Computed `risk_level` ∈ {LOWER RISK, MEDIUM RISK}.**

All numeric thresholds (`MEMECOIN_RISK_*`) are **configurable**, matching the
existing config style (`config/dexscreener.php`, `config/historical.php`).
Nothing here changes the $5M/$200M/age/volume/liquidity>0 rules.

### 14.2 RISK WATCH (visible, flagged, not hidden)

A token that **passes A** (genuinely qualified on market cap) but **fails one or
more of B–H** goes to a separate **RISK WATCH** section, showing:

- its `risk_level` (HIGH / CRITICAL / RISK UNKNOWN),
- the specific failed checks with source + value + `checked_at`,
- `data_completeness`,
- an explicit disclaimer: *"Qualified by market cap. Failed one or more risk
  checks — shown for transparency. This is not a safe-to-invest signal."*

### 14.3 DISQUALIFIED (dropped entirely)

Only when **A fails** (never qualified on market cap) — unchanged from today.
Risk checks never *remove* a token from the dataset; they only route it between
MAIN LIST and RISK WATCH.

---

## 15. Recommended MVP Architecture

*(Design only — not being built in Step 23.)*

```
DISCOVER → NORMALIZE → FILTER → SCORE → SNAPSHOT → [RISK SCREEN] → RELATE → EXPLAIN → DISPLAY
```

- **New service dir** `app/Services/Risk/` — mirrors `app/Services/Historical/`:
  - `GoPlusClient` — EVM `token_security`, `solana/token_security`,
    `rugpull_detecting`, `supported_chains`; batch; resilient; never throws into
    the pipeline; key optional (`GOPLUS_APP_KEY`, server-side only, never to
    React).
  - `GeckoTerminalInfoClient` — `/info` for holder buckets + Solana authorities +
    honeypot (reuse the existing GeckoTerminal adapter/base).
  - `HolderConcentrationAnalyzer` — exclusion list (burn/LP/CEX/bridge/lock),
    effective-% computation.
  - `LiquidityRiskAnalyzer` — pool/DEX spread from already-fetched DexScreener
    pairs; LP lock from GoPlus.
  - `ChartShapeAnalyzer` — pure function over `market_snapshots` +
    `pump_events` (+ optional GT OHLCV for thin-history tokens). **No new
    external call for tokens we already snapshot.**
  - `RiskAssessmentService` — orchestrates, produces a `RiskAssessment` value
    object: `score`, `level`, `data_completeness`, `signals[]` (each
    `{key, state, value, source, checked_at, weight, contribution}`),
    `failed_hard_checks[]`.
  - `RiskAssessmentRecorder` — upsert, idempotent.
- **New table** (later, not now) `risk_assessments` — one row per token
  (`unique(token_id)`), re-evaluable, `checked_at`, cooldown (proposed 6–12h,
  `MEMECOIN_RISK_RECHECK_HOURS`), stores the `signals[]` JSON (no provider
  payloads — same discipline as `evidences` / `historical_peak_evidences`).
- **New command** `memecoins:screen-risk [--force] [--token=chain:addr]`,
  scheduled a few minutes **after** discovery (offset like
  `detect-pumps`/`collect-evidence`), `withoutOverlapping`, per-run budget,
  `scheduler` container. **Never** called from a read endpoint.
- **Read API**: `GET /api/memecoins` gains `risk_level` / `risk_score` /
  `data_completeness` / `risk_watch` (bool) per row + `?list=main|risk_watch`.
  `GET /api/memecoins/{chain}/{addr}` gains a `risk` block (full `signals[]`).
  Read endpoints **never** call GoPlus/GeckoTerminal — DB only, same rule as
  every other read path.
- **Frontend**: MAIN LIST unchanged in spirit; add a **RISK WATCH** section
  (like "Recently Crossed $5M") and a risk chip per row; detail page gets a
  "Risk screening" card listing each check, its value, its source, its
  timestamp, and the UNKNOWN/NOT_AVAILABLE count. Wording rules from §2
  enforced in the component.
- **Config** `config/risk.php` — all thresholds, band edges, dimension weights,
  exclusion address lists, cooldowns, `data_completeness` minimum,
  per-chain enable flags, provider toggles.
- **Docs** `docs/risk-screening.md` at implementation time.

External calls per screened token (worst case): 1 GoPlus (`token_security` or
`solana/token_security`, batchable) + 1 GeckoTerminal `/info` + 0–1 GeckoTerminal
OHLCV (only thin-history tokens). DexScreener pairs are already fetched during
enrichment — reuse, don't re-call. GoPlus batching keeps a full run to a handful
of requests.

---

## 16. Known Limitations

1. **Top-trader flow is permanently unavailable** for free (§6). No insider /
   sniper / dev-dump detection at the wallet level.
2. **Holder data is top-10 only** — no true distribution coefficient; whales
   ranked 11+ are invisible.
3. **Provider lag on fresh tokens** — GoPlus/GeckoTerminal often have no data for
   a token < 1–3h old, exactly when risk is highest. These tokens land in RISK
   WATCH as "RISK UNKNOWN" until a later screen pass fills in data.
4. **EVM tax fields are simulation-based heuristics** (Reliability B), not a
   guarantee; `slippage_modifiable` means today's reading can be wrong tomorrow.
5. **Solana has no tax/open-source/proxy concept** — the authority model is a
   good analogue but not identical; cross-provider agreement is lower on Solana.
6. **LP-lock detection only recognises known lockers** — an unknown custom
   locker reads as UNKNOWN, and a genuine burn to a non-standard address may be
   missed.
7. **Proxy admin / timelock status is not reliably free** — most proxies will
   stay HIGH RISK even if actually safe.
8. **Chain coverage is uneven** — Robinhood and long-tail EVM chains frequently
   produce UNKNOWN contract security; the model must not punish them for it
   (§11 rule 3), which means some genuinely risky tokens on thin-coverage chains
   will sit in RISK WATCH as "RISK UNKNOWN" rather than "HIGH RISK".
9. **Point-in-time** — a token can pass today and be re-proxied / un-renounced /
   re-taxed tomorrow; the recheck cooldown bounds staleness but does not
   eliminate it.
10. **No image/chart vision** (by design) — chart-shape is numeric only, so a
    shape that only reads visually (e.g. a subtle distribution wall) is not
    captured.
11. **GoPlus / GeckoTerminal are third parties** — their heuristics, coverage,
    and rate limits can change without notice; the pipeline must degrade to
    UNKNOWN, never fail.
12. **`is_mintable` UNKNOWN → HIGH RISK** (§8.3, §11 rule 5) will occasionally
    over-flag a safe fresh token whose contract simply wasn't analysed yet —
    accepted trade-off, and it routes to RISK WATCH (visible), not DISQUALIFIED.

---

## 17. Decision

**Recommended for the implementation step that follows Step 23:**

1. **Adopt GoPlus as the primary risk/security provider**, GeckoTerminal `/info`
   as the holder-concentration + Solana-authority + honeypot cross-check,
   DexScreener (already integrated) as the liquidity-shape + buy/sell-balance
   source, and **our own `market_snapshots` + `pump_events`** as the chart-shape
   source. No scraping, no undocumented endpoints, no WebSocket, no vision.

2. **Keep the existing $5M–$200M / age ≤ 30d / volume>0 / liquidity>0
   market-cap qualification exactly as is.** Risk screening is a **layer on
   top**, never a change to `HistoricalPeakEvidence::qualifies()` or the
   discovery pipeline's qualification step.

3. **Introduce two homepage lanes:**
   - **MAIN LIST** = qualified on market cap **and** `risk_level ∈ {LOWER RISK,
     MEDIUM RISK}` **and** `data_completeness ≥ 0.5` **and** no CRITICAL/HIGH
     hard flag (§14.1).
   - **RISK WATCH** = qualified on market cap but failed ≥ 1 risk check —
     **visible, flagged, explained**, never hidden. Includes "RISK UNKNOWN"
     (insufficient data) tokens.
   - **DISQUALIFIED** stays exactly what it is today (never qualified on market
     cap). Risk checks never delete tokens.

4. **Tri-state everything** (§11). UNKNOWN ≠ bad. The one documented exception is
   `is_mintable` UNKNOWN → HIGH RISK for the mint sub-signal.

5. **Do not build the score engine in Step 23.** The 0–100 transparent weighted
   model in §13 (weights: contract 30 / exit safety 15 / holders 18 / liquidity
   12 / pump-dump 12 / market structure 8 / age 5) is a **proposal to backtest**
   against our own qualified sample during implementation.

6. **All thresholds configurable** via a new `config/risk.php`; nothing
   hard-coded; provider keys server-side only.

7. **Language discipline is a hard requirement**, enforced in the frontend
   components: LOWER/MEDIUM/HIGH RISK, CRITICAL — AVOID, DISQUALIFIED, RISK
   UNKNOWN. Never "safe", "guaranteed", "good investment".

---

## Final report

### 1. Best security provider

**GoPlus Security API.** Free, no key required (optional free App Key raises
limits), documented, batchable, covers EVM (`token_security/{numeric_chain_id}`)
+ Solana (`solana/token_security`, different schema) + partial Robinhood
(`4663`), plus EVM-only `rugpull_detecting` and an authoritative
`supported_chains`. Survived 8+ rapid recon calls with no throttling and no
rate-limit headers hit. **GeckoTerminal `/info`** is the recommended secondary
(holder buckets, Solana mint/freeze authority, honeypot flag, `gt_score`).
DexScreener provides **no** security fields.

### 2. Available security fields

- **EVM (GoPlus):** `is_open_source`, `is_proxy`, `is_mintable`, `owner_address`,
  `can_take_back_ownership`, `hidden_owner`, `owner_change_balance`,
  `selfdestruct`, `external_call`, `buy_tax`, `sell_tax`, `transfer_tax`,
  `cannot_buy`, `cannot_sell_all`, `slippage_modifiable`,
  `personal_slippage_modifiable`, `is_honeypot`, `transfer_pausable`,
  `is_blacklisted`, `is_whitelisted`, `is_anti_whale`, `anti_whale_modifiable`,
  `trading_cooldown`, `is_in_dex`, `is_true_token`, `is_airdrop_scam`,
  `fake_token`, `trust_list`, `other_potential_risks`, `note`, plus
  `rugpull_detecting` composite.
- **Solana (GoPlus):** `mintable{status,authority}`, `freezable`, `closable`,
  `non_transferable`, `balance_mutable_authority`, `metadata_mutable`,
  `default_account_state(+_upgradable)`, `transfer_fee(+_upgradable)`,
  `transfer_hook(+_upgradable)`, `trusted_token`, `creators[]`, `dex[]`,
  `metadata`.
- **GeckoTerminal `/info` (EVM + Solana):** `mint_authority`, `freeze_authority`,
  `is_honeypot` (nullable), `gt_score`, `holders.count`,
  `holders.distribution_percentage`.
- Missing behavior: key present with value = MEASURED; `""`/`[]`/`null` or key
  absent = UNKNOWN. Never coerce UNKNOWN to a safe default (mint is the single
  documented exception → HIGH RISK).

### 3. Holder data

- **Count:** GoPlus `holder_count` and GeckoTerminal `holders.count` (EVM +
  Solana). Both lag new tokens by minutes–hours.
- **Concentration:** GoPlus `holders[]` = **top 10 only**, each with address,
  `percent`, `is_contract`, `is_locked`, `tag`; plus `owner_percent`,
  `creator_percent`. GeckoTerminal gives bucketed percentages (top_10 / 11–30 /
  31–50 / rest) with **no addresses**.
- **Usable, with mandatory exclusions:** net out burn / LP-pair / known-CEX /
  bridge / locker addresses before computing effective concentration (GoPlus
  addresses + tags make this possible; GeckoTerminal cannot).
- **Not usable as a blanket rule:** `holder_count >= 1000`. Use **holders per
  $1M market cap** and **age-percentile vs the qualified cohort** instead.
- No true distribution coefficient (top-10 ceiling). `holders:[]` → UNKNOWN.

### 4. Liquidity data

- **Depth & shape:** DexScreener `/token-pairs/v1` / `/tokens/v1` — every
  indexed pair with `liquidity.usd`, `volume.h24`, `dexId`, `pairCreatedAt`.
  Derive `pool_count`, `dex_count`, `largest_pool_share`, `total_liquidity_usd`,
  `single_pool`.
- **LP lock / burn:** GoPlus `lp_holders[]` (EVM, top 10) with `is_locked` +
  locker `tag`, `lp_holder_count`, `lp_total_supply`. **EVM only, partial** —
  only recognises known lockers; Solana `lp_holders` usually empty.
- **Vol/liq ratio bands (heuristic, not proof):** `<2× / 2–5× / 5–10× / >10×`.
  `10× ≠ scam`.
- **Multiple pools ≠ safe** — check `largest_pool_share`; 98%-in-one-pool is
  effectively single-pool.
- DexScreener gives no lock data at all.

### 5. Top-trader availability

**NOT_AVAILABLE from any free, official, documented API.** The DexScreener web
UI "Top Traders" tab is not in the public API; no other free provider exposes
per-wallet bought/sold/PnL. A line like `Bought: --` / `Sold: $600K` must
**never** be used to infer insider/dev allocation without on-chain evidence.
This dimension is recorded `NOT_AVAILABLE` and contributes 0 to the score.
Would require a paid swap indexer (Bitquery/Dune) or self-hosted archive
node — out of scope.

### 6. Chart-shape feasibility

**Feasible, numeric only, no new provider, no vision.** Verified from live
data: SHRUB has 38 `market_snapshots` at ~10-min cadence →
**39.4% peak-to-current drawdown computed directly**; **81 `pump_events`**
already exist as ready-made inputs. Signals: `peak_to_current_drawdown_pct`,
`max_runup_pct`, `max_drawdown_pct` (rolling 6h windows), `round_trip`,
`volume_collapse`, `time_since_peak_hours`, `has_completed_pump_event`. For
tokens with < ~6 snapshots, **GeckoTerminal OHLCV `/hour`** (full history for
pools ≤ 30d; price+volume only) is the fallback. < 6 snapshots **and** no GT
pool → `INSUFFICIENT_HISTORY`, never "no pump-dump detected".

### 7. Recommended hard filters (MAIN LIST gates — DISQUALIFY from main, route to RISK WATCH)

1. Existing market-cap qualification unchanged ($5M–$200M verified/observed peak,
   age ≤ 30d, volume>0, liquidity>0). **Failing this = DISQUALIFIED entirely.**
2. **No CRITICAL flag:** honeypot, `sell_tax` ≥ 100%, `cannot_sell_all`, Solana
   `freezable`/`non_transferable`/`balance_mutable_authority` active,
   `is_airdrop_scam`, `fake_token`.
3. **Mint renounced and MEASURED:** EVM `is_mintable == "0"` / Solana `mintable`
   fully renounced. Mint UNKNOWN → RISK WATCH.
4. **Not an upgradeable proxy with a live admin** (EVM `is_proxy == "0"` or
   provably renounced admin).
5. **`hidden_owner == "0"`.**
6. **Sell tax MEASURED and ≤ ~10%**, and `slippage_modifiable == "0"` (or tax
   measured 0 + owner renounced).
7. **LP not a single point of failure:** locked/burned majority **or** ≥ 2 real
   pools/DEXes; not `lp_holder_count == 1` EOA.
8. **Effective top-1 holder %** (LP/burn/CEX/bridge excluded) below configurable
   ceiling (~25–35%); **creator effective %** below ~15–20%.
9. **`data_completeness` ≥ ~0.5.**
10. **Not a currently-completed pump-and-dump** (`round_trip` + drawdown > ~70% +
    `volume_collapse`).
11. **Computed `risk_level` ∈ {LOWER RISK, MEDIUM RISK}.**

All thresholds configurable (`config/risk.php`).

### 8. Recommended soft-risk signals (contribute to score, do not gate)

- Buy tax 2–10%, transfer tax, `transfer_pausable`, `is_anti_whale` +
  `anti_whale_modifiable`, `trading_cooldown`, `is_blacklisted` mechanism
  present.
- `can_take_back_ownership` with a renounced owner (weakens the renounce).
- Holders per $1M market cap; holder-count age-percentile.
- Top-5 / top-10 effective concentration (below the top-1 hard ceiling).
- Vol/liq ratio band; buy/sell transaction balance
  (`buys/(buys+sells)` — sells > buys is **not** scam proof).
- `pool_count` / `dex_count` / `largest_pool_share`.
- Chart-shape: `max_drawdown`, `time_since_peak`, partial `round_trip`.
- `community_takeover` membership (neutral-ish context).
- `gt_score` (GeckoTerminal composite) as a weak cross-check.
- Pool age vs configurable soft thresholds (age weight only 5%).

### 9. Missing-data treatment

Tri-state, distinguishable from the raw response: **MEASURED** (key + meaningful
value), **UNKNOWN** (`""` / `[]` / `null` / key absent), **NOT_AVAILABLE**
(`top_trader_analysis` — never obtainable). Rules: `owner_renounced = UNKNOWN`
means UNKNOWN not NO; `sell_tax = ""` means UNKNOWN not 0%; a chain with no
security provider = contract-security UNKNOWN, **not** an automatic HIGH RISK.
UNKNOWN / NOT_AVAILABLE contribute **0** to the score and are listed explicitly
in the UI. **One documented exception:** `is_mintable` UNKNOWN → HIGH RISK for
the mint sub-signal. A verdict built from < 50% measured signals is labelled
**"RISK UNKNOWN — insufficient data"** and routed to RISK WATCH, not MAIN LIST.

### 10. Chain coverage

| Tier | Chains | Contract-security quality |
|---|---|---|
| **Best** | Ethereum, BSC, Base, Arbitrum, Polygon, Avalanche (+ Optimism) | Full EVM GoPlus field set + `rugpull_detecting` + GeckoTerminal `/info` |
| **Good** | Solana | GoPlus `solana/token_security` authority model + GeckoTerminal `/info` cross-check (no tax/proxy concept) |
| **Medium** | Tron | Verify GoPlus `supported_chains` at implementation; GeckoTerminal partial |
| **Low–Medium** | Robinhood (`4663`) | GoPlus partial (many tokens absent) → often UNKNOWN; GeckoTerminal `robinhood` net is the fallback |
| **Varies** | other/long-tail EVM | Only if in GoPlus `supported_chains`; else contract-security = UNKNOWN (not a fail) |

Cache GoPlus `supported_chains`; maintain a 4-way chain map
(DexScreener ↔ GoPlus ↔ GeckoTerminal ↔ CoinGecko), extending
`config/historical.php` `chain_map`. GeckoTerminal slugs differ from DexScreener
slugs (`eth`/`ethereum`, `avax`/`avalanche`, `polygon_pos`/`polygon`).

### 11. Proposed MAIN LIST rules

**MAIN LIST = lower-risk qualified memecoins.** A token shows on the homepage
main list iff:

- it passes the **unchanged** market-cap qualification ($5M–$200M verified/
  observed peak, age ≤ 30d, volume>0, liquidity>0), **and**
- it passes **all hard filters** in report §7 (no CRITICAL flag; mint renounced
  & measured; not a live-admin proxy; no hidden owner; sell tax measured ≤ ~10%
  & fixed; LP not a single point of failure; effective top-1 / creator % under
  ceilings; `data_completeness ≥ ~0.5`; not a currently-completed pump-dump),
  **and**
- computed `risk_level ∈ {LOWER RISK, MEDIUM RISK}`.

Displayed with a risk chip (LOWER / MEDIUM) and a link to the full per-signal
breakdown. No "safe" language anywhere.

### 12. Proposed RISK WATCH rules

**RISK WATCH = qualified by market cap but failed one or more safety/risk
checks — visible, not hidden.** A token shows here iff:

- it passes the market-cap qualification (**A**), **but**
- it fails ≥ 1 of the hard filters (§7) — i.e. `risk_level ∈ {HIGH RISK,
  CRITICAL — AVOID}` **or** `RISK UNKNOWN` (`data_completeness < ~0.5`, e.g. a
  brand-new token or an unsupported chain).

Each RISK WATCH row shows: `risk_level`, the exact failed checks (signal,
source, value, `checked_at`), `data_completeness`, and the disclaimer
*"Qualified by market cap. Failed one or more risk checks — shown for
transparency. Not a safe-to-invest signal."*

Tokens that **fail the market-cap qualification** are **DISQUALIFIED** (dropped)
exactly as today — risk screening never deletes a token, it only routes
qualified tokens between MAIN LIST and RISK WATCH.

---

### Final product question

**"Which criteria should determine whether a coin appears in the MAIN homepage
list?"**

The unchanged market-cap qualification **plus** a conservative risk gate:
no CRITICAL trap (honeypot / 100% sell tax / unsellable / Solana freeze-or-
balance-mutate authority), a **measured** renounced mint, no live-admin proxy,
no hidden owner, a **measured** sell tax ≤ ~10% that is fixed, LP that is not a
single withdrawable position, no single non-infrastructure wallet holding more
than ~25–35% of effective supply, at least ~50% of the risk checks actually
performed, not a chart that has already completed a >70% round-trip crash, and a
computed risk level of LOWER or MEDIUM. Every threshold configurable; the
existing $5M–$200M / 30-day / volume / liquidity rules untouched.

**"Which coins should be visible but marked HIGH RISK rather than completely
hidden?"**

Every token that genuinely qualified on market cap but tripped one or more of
those risk checks — un-renounced or unanalysed mint, upgradeable proxy, elevated
or unknown taxes, unlocked or single-holder LP, concentrated holders, a
completed pump-and-dump shape, or simply too little data to judge (new token /
unsupported chain → "RISK UNKNOWN"). These go to a clearly-labelled **RISK
WATCH** shelf with the specific failure reasons shown. Transparency over hiding:
users see the token *and* why it failed. Only tokens that never qualified on
market cap are dropped entirely.

---

## 18. Implementation decisions (Step 24)

This reconnaissance was implemented in **Step 24**. See
[risk-screening.md](risk-screening.md) for the full spec. Key decisions and
where they diverge from the proposals above:

- **Adopted:** GoPlus as primary (`GoPlusSecurityClient`), GeckoTerminal `/info`
  secondary (`GeckoTerminalInfoClient`), DexScreener `/token-pairs/v1` for
  liquidity structure (`DexScreenerLiquidityProbe`, reuses `DexScreenerClient`),
  our own `market_snapshots` + `pump_events` for chart shape
  (`ChartShapeAnalyzer`). No scraping, no undocumented endpoints, no WebSocket,
  no vision, no AI.
- **Market-cap qualification unchanged** — risk screening is a pure layer
  (`Token::scopeMarketCapQualified` + `MainListDecision`). Never touches
  `HistoricalPeakEvidence`, `observed_peak_market_cap`, pump events, or evidence
  (test-guarded).
- **Score weights implemented as proposed** — contract 0.30 / exit safety 0.15 /
  holders 0.18 / liquidity 0.12 / pump-dump 0.12 / market structure 0.08 / age
  0.05. All `MEMECOIN_RISK_W_*` configurable; band edges 25 / 50 / 75.
- **Two lanes:** MAIN LIST (`GET /api/memecoins`, now screened) + RISK WATCH
  (`GET /api/memecoins/risk-watch`, new). DISQUALIFIED unchanged.
- **72h maturity gate** (`MEMECOIN_MAIN_MIN_AGE_HOURS`) applied **live in the
  read query** so it never goes stale (the assessment's `main_list_eligible`
  column deliberately excludes it).
- **Tri-state everywhere.** `RiskSignalDraft` is `MEASURED` / `BAD` / `UNKNOWN`
  / `NOT_AVAILABLE`; UNKNOWN & NOT_AVAILABLE contribute 0 to the score. Low
  `data_completeness` → `RISK UNKNOWN` (distinct level), not HIGH.
- **`is_mintable` exception — narrowed.** `is_mintable = true` (explicit) is a
  HIGH hard override, as recommended. `is_mintable` **UNKNOWN** is *not* forced
  to HIGH in Step 24 (the recon doc's §17.4 "lean HIGH" suggestion): it is
  scored as UNKNOWN and counts against `data_completeness`. Rationale: the core
  rule "never treat UNKNOWN as YES/NO" wins, an unread mint field almost always
  means a very fresh contract, and `require_screening` + the completeness gate
  already route such tokens to RISK WATCH ("RISK UNKNOWN"). Documented in
  risk-screening.md §11.
- **Top traders — not implemented.** Stored as a `NOT_AVAILABLE` signal
  (`top_trader_analysis`), contributes 0, never inferred.
- **Community takeover — deferred.** A non-applicable `community_takeover`
  placeholder signal; not fetched per-token in this pass (contextual only).
