# Memecoin Risk & Safety Screening (Step 24)

A conservative, **deterministic** risk screen layered **on top of** the existing
market-cap qualification. It routes an already-qualified token to one of two
homepage lanes:

```
MARKET-CAP QUALIFICATION  (Step 19 — unchanged)
        │  age ≤ 30d · verified/observed peak in [$5M, $200M] · volume>0 · liquidity>0
        ▼
RISK SCREENING  (this step — memecoins:screen-risk)
        ▼
┌───────────────────────────┐        ┌───────────────────────────┐
│ MAIN LIST                 │        │ RISK WATCH                │
│ LOWER / MEDIUM risk       │        │ HIGH / CRITICAL / UNKNOWN  │
│ mature (≥ 72h) · screened │        │ or too young · visible,   │
│                           │        │ flagged, never hidden     │
└───────────────────────────┘        └───────────────────────────┘
```

**No AI.** Scoring is a transparent weighted sum with hard overrides. The LLM
stays in the pump-explanation / narrative features only.

---

## 1. Main-list purpose

`GET /api/memecoins` now returns **only** tokens that are BOTH:

1. **market-cap qualified** (Step 19 — `Token::scopeMarketCapQualified`), AND
2. **pass the risk screen**:
   - **B.** age ≥ `MEMECOIN_MAIN_MIN_AGE_HOURS` (default **72h**),
   - **C.** `risk_level ∈ {LOWER, MEDIUM}`,
   - **D.** `data_completeness ≥ MEMECOIN_RISK_MIN_DATA_COMPLETENESS` (0.50) and
     `screening_status = completed`,
   - **E./F.** no CRITICAL / HIGH hard filter tripped
     (`risk_assessments.hard_override_signal IS NULL`).

Each row carries `risk_level`, `risk_score`, `data_completeness`, and
`risk_summary` — a list of **pre-written** concise phrases (never LLM prose,
never the word "safe").

The maturity gate (B) is applied **live in the read query** so it never goes
stale; the rest is read from the persisted assessment.

## 2. Risk Watch purpose

`GET /api/memecoins/risk-watch` returns tokens that **are** market-cap qualified
but **fail** the main-list screen — HIGH / CRITICAL / RISK UNKNOWN risk, too
young, insufficient security data, or a hard filter. They are shown **for
transparency** — never deleted, never hidden. Each row exposes `failed_signals`
(the BAD signals with source + severity + pre-written explanation) and `reasons`
(pre-written reason phrases — only what was actually measured).

MAIN LIST = "qualified + lower/medium screening risk".
RISK WATCH = "qualified by market cap, but risk checks require caution".
DISQUALIFIED (dropped) = only tokens that were never market-cap qualified —
risk screening never removes a token from the dataset.

## 3. Risk score

`risk_score` is a deterministic **0–100** heuristic screening score. Higher =
more risk. **It is NOT a probability of scam / rug / loss.**

```
score = 100 · Σ  weight[dimension] · clamp( Σ signal contributions in dimension , 0, 1 )
```

| Dimension | Weight (`MEMECOIN_RISK_W_*`) |
|---|---|
| Contract security | 0.30 |
| Exit safety (tax / honeypot) | 0.15 |
| Holder distribution | 0.18 |
| Liquidity | 0.12 |
| Pump-dump shape | 0.12 |
| Market structure | 0.08 |
| Age | 0.05 |

Level from the score band (`MEMECOIN_RISK_LEVEL_*_AT`):

| Score | Level |
|---|---|
| 0–24 | LOWER |
| 25–49 | MEDIUM |
| 50–74 | HIGH |
| 75–100 | CRITICAL |

**Precedence for the final level:**

1. a CRITICAL / HIGH **hard-override** signal always wins (we measured something
   dangerous); the triggering signal key is stored in `hard_override_signal` and
   shown in the UI;
2. otherwise `data_completeness < min` or `screening_status ≠ completed` ⇒
   **RISK UNKNOWN** (distinct from HIGH — "insufficient security data", never
   "safe");
3. otherwise the score band.

`UNKNOWN` and `NOT_AVAILABLE` signals **never contribute to the score.**

## 4. Hard filters

A single measured signal forces the level regardless of the score. All
thresholds configurable.

**CRITICAL — AVOID**

- `is_honeypot = true`
- `cannot_sell_all = true`
- sell tax ≥ `MEMECOIN_RISK_SELL_TAX_CRITICAL_AT` (100%)
- Solana `freezable` authority active (`MEMECOIN_RISK_SOLANA_FREEZE_CRITICAL`)
- Solana `balance_mutable_authority` active
- Solana `non_transferable = true`
- `owner_change_balance = true`
- `fake_token = true` / `is_airdrop_scam = true`

**HIGH RISK**

- `is_mintable = true` (**explicit** — see §11 exception) — `MEMECOIN_RISK_MINTABLE_LEVEL`
- `is_proxy = true` (live implementation)
- `hidden_owner = true`
- `selfdestruct = true`
- buy or sell tax ≥ `MEMECOIN_RISK_TAX_HIGH_AT` (10%)
- `slippage_modifiable = true` (tax not fixed)
- `cannot_buy = true`
- Solana `transfer_hook` present
- effective top-1 holder ≥ `MEMECOIN_RISK_TOP1_HIGH_AT` (35%) / ≥ 50% ⇒ CRITICAL
- creator holds ≥ `MEMECOIN_RISK_CREATOR_HIGH_AT` (20%)
- no usable liquidity (< `MEMECOIN_RISK_MIN_LIQUIDITY_USD`)
- single thin pool **and** no LP-lock/burn evidence
- completed round-trip crash: run-up ≥ 100% **and** peak-to-current drawdown ≥
  `MEMECOIN_RISK_CRASH_DRAWDOWN_AT` (70%) **and** volume collapse

## 5. Soft signals (contribute to the score, do not gate)

Buy tax 2–10% bands, `transfer_pausable`, `is_blacklisted` mechanism,
`can_take_back_ownership`, `external_call`, `is_open_source = false`,
`owner_renounced = false` (live owner), Solana `metadata_mutable`,
volume/liquidity turnover bands (`<2× / 2–5× / 5–10× / >10×` — "very high
turnover relative to available liquidity", never "10x = scam"), buy/sell balance
(`buy_share = buys/(buys+sells)` — sells > buys is **not** scam proof),
holders-per-$1M-market-cap, top-5 / top-10 effective concentration,
`peak_to_current_drawdown`, partial round-trip, `market_cap_for_age` warning
bands, multi-pool spread (a risk-**reduction** signal, never "safe").

## 6. Age rule

`MEMECOIN_MAIN_MIN_AGE_HOURS` (default **72**). A token younger than this
**cannot** enter the MAIN LIST — even at LOWER risk. It may still appear on RISK
WATCH if market-cap qualified. This is a risk-management rule, **not** a claim
that every < 72h token is bad. The age signal is shown explicitly
(`token_age_hours` in the assessment; `age_days` on every row).

Age / market-cap heuristic warning bands (`risk.age_market_cap_bands`, soft):
`< 3h` with MC > $10M · `< 24h` with MC > $20M · `< 72h` with MC > $20M.

## 7. Holder metrics

Computed from GoPlus `holders[]` (top 10) + `creator_percent` / `owner_percent`,
with GeckoTerminal `/info` `holders.distribution_percentage.top_10` as a
magnitude cross-check.

**Mandatory exclusions before any concentration figure:** burn / dead /
incinerator addresses (`MEMECOIN_RISK_BURN_ADDRESSES`), LP-pair contracts
(matched against the DexScreener pool list), GoPlus `is_locked` holders, and any
holder whose GoPlus `tag` matches `MEMECOIN_RISK_INFRA_TAGS` (lockers, CEX
custody, bridges). An exchange / LP address is **never** treated as an
individual whale.

Derived: `holder_count`, `holders_per_1m_market_cap`, `top_1/5/10_effective_pct`,
`creator_pct`, `owner_pct`. Missing holder data ⇒ `holder_distribution` is
UNKNOWN — **no fabricated count**. `holder_count ≥ 1000` is **not** a universal
rule.

## 8. Contract security

**GoPlus is the primary provider.** EVM: `token_security/{numeric_chain_id}`
(+ `rugpull_detecting/{id}`, best-effort). Solana: `solana/token_security` (a
**different** authority-model schema — mint / freeze / close / balance-mutate /
metadata / transfer-hook authorities). GeckoTerminal `/info` cross-checks mint /
freeze authority + honeypot.

Ownership, proxy/upgradeability, and supply mutability are **separate** signals.
A zero/dead `owner_address` renounces the *owner role only* — a renounced owner
on a live proxy is still HIGH.

## 9. Liquidity

From DexScreener `/token-pairs/v1` (reused by the screening command — never a
read API): `pool_count`, `dex_count`, `largest_pool_share`, `single_pool`
(count ≤ 1 **or** one pool ≥ `MEMECOIN_RISK_DOMINANT_POOL_SHARE` of total),
`total_liquidity`. LP lock/burn from GoPlus `lp_holders[]` (`is_locked` +
recognised locker tags + burn address). **Multiple pools ≠ safe** — it is only a
risk-reduction signal.

## 10. Pump-dump

Numeric only — from OUR `market_snapshots` (+ `pump_events`). No chart images,
no vision. `peak_to_current_drawdown_pct`, rolling-`window_hours` `max_runup` /
`max_drawdown`, `round_trip` (run-up ≥ `round_trip_runup` then retrace ≥
`round_trip_retrace` of the gain), `volume_collapse`, `time_since_peak`, and a
`severeShortPumpThenCollapse` check against completed `PumpEvent`s.
`< MEMECOIN_RISK_PUMP_MIN_SNAPSHOTS` (6) snapshots ⇒ `INSUFFICIENT_HISTORY`,
contributes 0, never "no pump-and-dump detected".

Community-takeover context and per-wallet **top-trader** data are noted but not
scored: top-trader per-wallet bought/sold is `NOT_AVAILABLE` from any free
official API (Step 23) and is **never inferred**.

## 11. Missing data — TRI-STATE

Every signal is **MEASURED** / **BAD** / **UNKNOWN** (+ **NOT_AVAILABLE**).

| Raw provider value | State |
|---|---|
| a real value (`"0"`, `"1"`, `"0.05"`, a list) | MEASURED / BAD |
| `null` / `""` / key missing / unsupported chain | **UNKNOWN** |
| structurally impossible to obtain (top traders) | **NOT_AVAILABLE** |

- `owner_renounced = null` ⇒ UNKNOWN, **never** "not renounced".
- `sell_tax = ""` ⇒ UNKNOWN, **never** 0%.
- A chain no security provider covers ⇒ contract-security dimension UNKNOWN,
  **not** an automatic HIGH RISK.
- UNKNOWN / NOT_AVAILABLE contribute **0** to the score and count against
  `data_completeness` (except NOT_AVAILABLE / non-applicable signals, which are
  excluded from the denominator).
- `data_completeness < min` ⇒ `risk_level = UNKNOWN` and main-list eligibility
  `false` → RISK WATCH, labelled *"Risk unknown — insufficient security data."*
  This is **different from HIGH RISK.**

### The documented `is_mintable` exception

Per Step 23, mint is the single most common memecoin rug vector.

- `is_mintable = true` (**explicitly** measured, EVM flag or a live Solana mint
  authority or GeckoTerminal `mint_authority`) ⇒ **hard override to
  `MEMECOIN_RISK_MINTABLE_LEVEL` (HIGH)** at minimum.
- `is_mintable` **UNKNOWN** ⇒ Step 24 does **not** claim the token is mintable
  and does **not** force HIGH on the mint sub-signal alone. It is scored as
  UNKNOWN (0 contribution) and counts against `data_completeness`. This is a
  deliberate, narrow refinement of the reconnaissance doc's "lean HIGH"
  suggestion: the core rule *"never treat UNKNOWN as YES or NO"* wins, and the
  `require_screening` + `data_completeness` gate already keeps under-screened
  tokens (which is where an unread mint field almost always occurs — very fresh
  contracts) off the MAIN LIST and on RISK WATCH.

## 12. Chain coverage

| Tier | Chains | Contract-security quality |
|---|---|---|
| Best | Ethereum, BSC, Base, Arbitrum, Polygon, Avalanche, Optimism | full EVM GoPlus set + `rugpull_detecting` + GeckoTerminal `/info` |
| Good | Solana | GoPlus authority model + GeckoTerminal cross-check |
| Medium | Tron | verify `risk.goplus_chain_map` coverage |
| Low–Medium | Robinhood + long-tail EVM | often UNKNOWN → RISK WATCH as "RISK UNKNOWN", **never** auto-HIGH |

`config('risk.goplus_chain_map')` maps DexScreener slug → GoPlus chain id
(`solana` is special-cased to `solana/token_security`). GeckoTerminal network
ids come from `config('historical.chain_map')` (`eth` ≠ `ethereum`, etc.).

## 13. Provider limits

- **GoPlus:** free, no key (optional `GOPLUS_APP_KEY` raises limits, server-side
  only). `GOPLUS_MAX_CALLS_PER_RUN` (60). 429 → skip, cached
  `GOPLUS_CACHE_TTL` (6h).
- **GeckoTerminal `/info`:** `MEMECOIN_RISK_GT_MAX_CALLS_PER_RUN` (30), reuses
  the historical GeckoTerminal cache/timeout.
- **DexScreener `/token-pairs/v1`:** reuses `DexScreenerClient` (already cached),
  `MEMECOIN_RISK_DEXSCREENER_MAX_CALLS_PER_RUN` (40).
- **Per-token scan cooldown** `MEMECOIN_RISK_SCAN_COOLDOWN_HOURS` (6) +
  **per-run cap** `MEMECOIN_RISK_MAX_TOKENS_PER_RUN` (15) — never-screened
  first, then oldest `screened_at`. `--force` ignores the cooldown;
  `--token=chain:address` screens one token.
- Every provider failure is caught → a non-OK lookup, never a thrown pipeline
  error. `screening_status` = `completed` (GoPlus returned data) / `partial`
  (only GeckoTerminal or DexScreener) / `failed` (nothing).

## 14. Why risk ≠ safety

Passing every check we can run means **"no automated red flags in the checks we
could perform"** — never "safe". The product never displays *safe coin*,
*guaranteed safe*, *good investment*, or *scam probability*. Allowed vocabulary:
**LOWER RISK · MEDIUM RISK · HIGH RISK · CRITICAL — AVOID · RISK UNKNOWN ·
DISQUALIFIED**. Every verdict is explainable — each contributing signal, its
value, its source, its `checked_at`, and whether it was measured or unavailable.
Risk is point-in-time (`screened_at`); a contract can be re-proxied or
un-renounced after a scan.

## 15. Limitations

1. **Top-trader flow is permanently unavailable** for free — no wallet-level
   insider / sniper / dev-dump detection.
2. **Holder data is top-10 only** — whales ranked 11+ are invisible; no true
   Gini/Nakamoto.
3. **Provider lag on fresh tokens** — GoPlus / GeckoTerminal frequently have no
   data for a token < 1–3h old, exactly when risk is highest → RISK WATCH as
   "RISK UNKNOWN" until a later pass.
4. **EVM tax fields are simulation heuristics**, not guarantees;
   `slippage_modifiable` means today's reading can be wrong tomorrow.
5. **Solana has no tax / open-source / proxy concept** — the authority model is
   an analogue, not identical.
6. **LP-lock detection only recognises known lockers** — a custom locker or a
   burn to a non-standard address reads as UNKNOWN.
7. **Proxy admin / timelock status is not reliably free** — most proxies stay
   HIGH even when actually safe.
8. **Uneven chain coverage** — thin-coverage chains produce UNKNOWN, so some
   genuinely risky tokens there sit as "RISK UNKNOWN" rather than "HIGH RISK".
9. **Point-in-time** — bounded by the recheck cooldown, not eliminated.
10. **No image/chart vision** — a shape that only reads visually is not captured.
11. **GoPlus / GeckoTerminal are third parties** — heuristics, coverage and
    limits can change; the pipeline degrades to UNKNOWN, never fails.

---

## Data model

| Table | Shape |
|---|---|
| `risk_assessments` | one CURRENT row per token (`unique(token_id)`, upserted). `risk_level` (LOWER/MEDIUM/HIGH/CRITICAL/UNKNOWN), `risk_score` (0–100), `data_completeness` (0..1), `screening_status` (completed/partial/failed), `hard_override_signal`, `main_list_eligible` (screen passed, **excludes** the live maturity gate), `screened_at`, `provider_version`, `notes`. No provider payloads. |
| `risk_signals` | one row per signal per assessment (`unique(risk_assessment_id, signal_key)`), **replaced** on every rescan. `signal_group`, `state`, `value`, `numeric_value`, `unit`, `severity`, `source`, `source_checked_at`, `explanation` (pre-written). No payloads. |

## Services (`app/Services/Risk/`)

`GoPlusSecurityClient` / `GoPlusSecurityLookup` · `GeckoTerminalInfoClient` /
`GeckoTerminalInfoLookup` · `DexScreenerLiquidityProbe` / `LiquidityStructure` ·
`ChartShapeAnalyzer` / `ChartShape` · `HolderConcentrationAnalyzer` /
`HolderConcentration` · `RiskSignalEvaluator` (+ `RiskSignalDraft`,
`TokenRiskContext`) · `RiskScoreCalculator` (+ `RiskAssessmentResult`) ·
`RiskSnapshotRecorder` · `RiskScreeningService` (+ `RiskScreeningRunResult`) ·
`MainListDecision` (the shared MAIN LIST / RISK WATCH decision).

## Command + schedule

```bash
docker compose exec backend php artisan memecoins:screen-risk [--force] [--token=chain:address]
```

```
Risk screening completed.

Tokens analyzed:    12
Main-list eligible: 7
Risk watch:         5
Lower risk:         5
Medium risk:        2
High risk:          3
Critical:           1
Unknown:            1
Skipped (cooldown): 4
Provider failures:  0
```

Scheduled `6,16,26,36,46,56 * * * *` — the discovery cadence, offset AFTER
discovery + historical qualification (top of the interval) and BEFORE the
evidence offset (:08). `withoutOverlapping(20)`, reuses the existing `scheduler`
container (no new container).

## APIs

- `GET /api/memecoins` — MAIN LIST. Adds `risk_level` / `risk_score` /
  `data_completeness` / `risk_summary` per row. PostgreSQL only.
- `GET /api/memecoins/risk-watch` — RISK WATCH. `?chain=` / `?limit=`.
  PostgreSQL only.
- `GET /api/memecoins/{chain}/{address}` — adds `data.risk_assessment`
  (`status`, `risk_level`, `risk_score`, `data_completeness`, `screened_at`,
  `hard_override_signal`, grouped `signals[]`, `disclaimer`). `status: "pending"`
  when not yet screened. Never triggers screening, never exposes a provider
  error.

## Frontend

Homepage: **🔥 Recently Crossed $5M** → **🟢 Main Memecoin List**
(Token / Chain / Age / Current MC / Peak MC / **Risk** / Volume / Liquidity;
compact `LOWER` / `MEDIUM` chip) → **⚠️ Risk Watch**
(Token / Chain / Age / Current MC / Peak MC / **Risk** / **Why flagged**; `HIGH`
/ `CRITICAL` / `RISK UNKNOWN` chip + only-measured reason phrases). Detail page
gains a **"Risk Assessment"** section (level, score, data completeness, last
screened, then signal groups with ✅ / ⚠ / ❓ per signal, each expandable for
source + checked time). Copy-CA and row-click behaviour unchanged; the risk
components never render "SAFE". The browser still calls only this app's Laravel
API (the DexScreener chart iframe is the only third-party content).
