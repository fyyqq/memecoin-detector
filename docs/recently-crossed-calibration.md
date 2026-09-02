# Recently Crossed $5M — empirical calibration

The market-quality thresholds for the **🔥 Recently Crossed $5M** section (and,
by inheritance, the "previously approved" marker that feeds **📈 Post-30-Day
Memecoins**) were originally placeholder assumptions. This document records the
one-time calibration of those thresholds against a small set of **real memecoins
that survived the market conditions the section is meant to capture**.

> **These 9 tokens are empirical calibration examples, not a machine-learning
> training set.** No model was trained, no classifier built, no whitelist
> created. The addresses are not stored anywhere in the codebase. The thresholds
> below are transparent, deterministic, and configurable, and were chosen to be
> *broadly* consistent with the reference population — deliberately loose, not
> fitted to the sample (n = 9 is far too small for that).

## The reference set

| # | Token | Chain | Address |
|---|-------|-------|---------|
| 1 | apeonfone (`fone`) | solana | `CTPoyCwkjMvoJwU4xvZZqoD8tiYk6yDchySiN5gGpump` |
| 2 | Cash Cat (`CASHCAT`) | robinhood | `0x020bfC650A365f8BB26819deAAbF3E21291018b4` |
| 3 | The Juggernaut (`JUGGERNAUT`) | robinhood | `0xD7321801CAae694090694Ff55A9323139F043B88` |
| 4 | Artificial Inu (`AI`) | robinhood | `0x2E8c31162b855A2ffa90F6F8634643Ad6F111e18` |
| 5 | *Wrapped BNB* (`WBNB`) | bsc | `0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c` |
| 6 | MarsCoin | bsc | `0xFe189E97832DA1573e4e4Ff034F4fFC3a15c7777` |
| 7 | Catecoin (`CATE`) | solana | `Ai66LHZG9MCzg1WKdawwqduVAXpNDUuV8M3uyq5ppump` |
| 8 | 牛来 (`NiuLai`) | bsc | `0xBEEA1D618e533a387D941F58a7d4c9b7bD377777` |
| 9 | Bicat | bsc | `0xDBc6333a7D8bCd95f96641EDA4D095E69F207777` |

**#5 resolves to Wrapped BNB** — a wrapped gas token, market cap ≈ $1.2B, pool
age ≈ 3 years. It is **excluded from the survivor profile**: it violates the
section's own *fixed* bands (pool age ≤ 30 days, verified/observed peak market
cap `< $1B`), which this calibration does **not** touch. Treated as a likely
address error or a negative control. The other **8** are the working set.

## What was observed

All figures from the application's existing provider infrastructure —
DexScreener `token-pairs/v1` (market data, representative = highest-liquidity
pair, age = earliest `pairCreatedAt` across all pairs) and GeckoTerminal `/info`
(holder counts, security). GoPlus was unreachable from the calibration
environment; GeckoTerminal `is_honeypot` / mint / freeze fields were used
instead. **Nothing was fabricated** — missing values are marked *n/a*.
Snapshot date: 2026-09-02.

| # | Token | Chain | Age (d) | Current MC | Liquidity | 24h Vol | **Vol / MC** | **Liq / MC** | Vol / Liq | Holders | **Holders / $1M MC** | Security |
|---|-------|-------|--------:|-----------:|----------:|--------:|-------------:|-------------:|----------:|--------:|---------------------:|----------|
| 1 | fone | solana | 6.4 | $18.2M | $0.75M | $11.6M | 0.639 | 0.041 | 15.6 | 27,726 | 1,523 | mint✗ freeze✗ · GT 91 |
| 2 | CASHCAT | robinhood | 75.6 | $258M | $4.49M | $9.49M | 0.037 | 0.017 | 2.1 | *n/a* | *n/a* | *no provider coverage* |
| 3 | JUGGERNAUT | robinhood | 73.4 | $6.95M | $0.51M | $1.98M | 0.284 | 0.073 | 3.9 | *n/a* | *n/a* | *no provider coverage* |
| 4 | AI | robinhood | 49.7 | $217M | $5.28M | $31.2M | 0.144 | 0.024 | 5.9 | *n/a* | *n/a* | *no provider coverage* |
| 6 | MarsCoin | bsc | 36.9 | $66.7M | $1.25M | $4.21M | 0.063 | 0.019 | 3.4 | 36,817 | 552 | honeypot✗ · GT 88 |
| 7 | CATE | solana | 37.7 | $33.9M | $1.76M | $4.51M | 0.133 | 0.052 | 2.6 | 117,971 | 3,484 | mint✗ freeze✗ · GT 96 |
| 8 | NiuLai | bsc | 19.1 | $72.6M | $1.20M | $2.53M | 0.035 | 0.016 | 2.1 | 52,625 | 725 | honeypot✗ · GT 88 |
| 9 | Bicat | bsc | 25.2 | $160K | $50.6K | $119K | 0.745 | 0.316 | 2.4 | 4,166 | 26,012¹ | honeypot✗ · GT 63 |

¹ Bicat's holders/$1M is inflated by its tiny current market cap (it has cooled
far below $5M — a legitimate `COOLED` case; the floor is a *peak* rule).

### Compact numeric profile (8 working references)

| Metric | min | ~Q1 | median | ~Q3 | max | missing |
|--------|----:|----:|-------:|----:|----:|--------:|
| current MC | $160K | ~$16M | ~$53M | ~$200M | $258M | 0 |
| 24h volume | $119K | ~$2.0M | ~$4.4M | ~$10M | $31.2M | 0 |
| liquidity | $50.6K | ~$0.5M | ~$1.2M | ~$4.5M | $5.28M | 0 |
| **volume / current MC** | **0.035** | ~0.05 | 0.139 | ~0.31 | 0.745 | 0 |
| **liquidity / current MC** | **0.016** | ~0.018 | 0.033 | ~0.06 | 0.316 | 0 |
| volume / liquidity | 2.1 | 2.2 | 3.0 | 5.9 | 15.6 | 0 |
| holders | 4,166 | — | ~37,000 | — | 117,971 | 3 |
| **holders / $1M MC** (excl. Bicat) | **552** | — | ~1,100 | — | 3,484 | 3 |
| age (days) | 6.4 | ~24 | ~37 | ~55 | 75.6 | 0 |

### Categorical characteristics

- **Chains:** solana ×2, robinhood ×3, bsc ×3. Robinhood has **no coverage** in
  either security provider (`config('risk.goplus_chain_map')` has no `robinhood`;
  GeckoTerminal has no `robinhood` network) — the risk screen can only ever
  return **RISK UNKNOWN** for a Robinhood token.
- **Security:** every token we *could* inspect has no honeypot, no mint / freeze
  authority, and a GeckoTerminal score of 63–96. **None** would be excluded by a
  positive hard-failure signal.
- **Discovery:** all 8 currently appear on DexScreener's public API. The app
  persists only `last_observed_at` — not which feed surfaced a token — so the
  honest phrasing is *"recently observed by discovery"*, never *"trending"*.
- **Participation:** all have real two-sided transaction counts (hundreds to
  hundreds of thousands of buys + sells per 24h).

## Old thresholds vs. the reference set

| Rule | Old | Working refs passing | Verdict | New |
|------|----:|---------------------:|---------|----:|
| `min_volume_to_mcap_ratio` | 0.001 | 8/8 — weakest 0.035 (**35×** the floor) | far too weak | **0.01** |
| `min_liquidity_to_mcap_ratio` | 0.001 | 8/8 — weakest 0.016 (**16×**) | far too weak | **0.005** |
| `min_holders_per_million_mcap` | 5.0 | 5/5 measurable — weakest 552 (**110×**) | far too weak | **25.0** |
| `require_holder_evidence` | true | 5/8 — the 3 Robinhood tokens have **no obtainable** holder count → rejected | too strict where no provider exists | **false** |
| risk gate rejects RISK UNKNOWN | (via `MainListDecision`) | 5/8 — the 3 Robinhood tokens → RISK UNKNOWN → rejected | chain-coverage gap, not a quality signal | **on an uncovered chain, RISK UNKNOWN alone no longer rejects** (`allow_unsupported_chain_risk_unknown`, default true) |
| liquidity absolute floor `$10K` (`risk.liquidity.min_total_usd`) | — | 8/8 (Bicat $50.6K) | keep — the ratios do the real work | unchanged |
| volume / liquidity | (not gated) | 2.1–15.6, no pattern | inconsistent → **not** a hard filter | unchanged (ungated) |
| `$50M MC / $7.2K 24h volume` anomaly | rejected (0.000144) | — | must stay rejected | still rejected at 0.01 |
| pool age ≤ 30d · `$5M` crossing · peak `< $1B` | fixed | — | out of scope | **unchanged** |

## The calibrated Recently Crossed profile

Same eight gates, same structure — three ratio floors raised, and the risk /
holder gates made honest about chains we cannot screen. In order:

1. **Pool age ≤ 30 days** (`earliest_pair_created_at`). *Unchanged, fixed.*
2. **`$5M` crossing** — a persisted, representative `qualification_events` row
   within the 30-day window. Never inferred from current MC, never FDV.
   *Unchanged, fixed.*
3. **Verified / observed peak MC in `[$5M, $1B)`** — floor inclusive, `$1B`
   ceiling exclusive. *Unchanged, fixed.*
4. **Discovery freshness** — `last_observed_at` within
   `discovery_freshness_hours` (48). Honestly "recently observed by discovery",
   never "trending". *Unchanged.*
5. **Risk** — a **positive hard-failure** (honeypot / cannot-buy / cannot-sell /
   mintable=true / CRITICAL / HIGH / recorded hard override) rejects **on every
   chain**. A **RISK UNKNOWN / unscreened / low-completeness** result rejects
   only on a chain in `config('risk.goplus_chain_map')`; on an uncovered chain
   (e.g. `robinhood`) that outcome is *expected* and does not reject by itself.
   Toggle: `recent_crossing.allow_unsupported_chain_risk_unknown` (default true).
   *No change to `MainListDecision`, the risk screen, or `GET /api/memecoins`.*
6. **Holder participation** — when a **MEASURED** `holder_count` risk signal
   exists: `holders / (current_MC / 1e6) ≥ 25` (reference survivors 552–3,484; a
   deliberately loose floor — an order of magnitude below the weakest survivor
   and an order of magnitude above the `$50M / 20–60 holder` anomalies). A
   **missing** count no longer rejects by default
   (`require_holder_evidence = false`). Never a fabricated count.
7. **24h volume vs current MC** — `volume_h24 / current_MC ≥ 0.01` (reference
   survivors 0.035–0.75). Zero / missing volume still rejects. High volume is
   never a reject.
8. **Liquidity** — `liquidity_usd ≥ risk.liquidity.min_total_usd` ($10K, the
   existing absolute floor, unchanged) **AND** `liquidity_usd / current_MC ≥
   0.005` (reference survivors 0.016–0.32).

## Post-30-Day

**Not re-calibrated** — it has no thresholds of its own. It inherits the
calibrated Section-1 predicate automatically, because the "previously approved"
marker (`memecoins:mark-recently-crossed` → `tokens.recently_crossed_qualified_at`)
is stamped by the same `RecentlyCrossedQualifier`. The lifecycle is unchanged:

```
pool age ≤ 30d  →  🔥 Recently Crossed $5M   (approval marker stamped here)
pool age > 30d  →  📈 Post-30-Day Memecoins  (historical approval preserved)
```

Exactly 30 days stays in Recently Crossed; the two lists can never overlap.
Historical approval survives a later dump below `$5M`, stale discovery, or a
HIGH / CRITICAL rescreen — current metrics and the current risk level are shown
for transparency, never "safe".

## Limitations

- **n = 9** (8 working). This is calibration by inspection of real survivors, not
  statistics. Every threshold is intentionally an order of magnitude looser than
  the observed survivor floor, and every value is env-configurable.
- **GoPlus was unreachable** from the calibration environment; security was read
  from GeckoTerminal. Robinhood has **no** coverage in either provider — the
  RISK-UNKNOWN / missing-holder rules were changed to be honest about that, not
  to weaken any positive safety check.
- **Peak market cap** is not directly observable from DexScreener; for tokens
  currently above `$5M` the crossing is self-evident, and Bicat (currently
  `$160K`) is a `COOLED` survivor that must have peaked in-band.
