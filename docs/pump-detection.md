# Pump Detection

> The detailed reference lives in
> [`sprint-1-discovery.md` → "Pump Event Detection (Step 16A)"](sprint-1-discovery.md#pump-event-detection-step-16a).
> This page is the short map of the three-stage pump pipeline.

## Final pipeline

```
DexScreener
  → MarketSnapshots            ~10-min observation series
  → PumpEvent      (Step 16A)  "WHEN did this coin pump?" — deterministic, no AI
  → Evidence       (Step 16B)  "What facts were present around the pump?" — no interpretation
  → AI Explanation (Step 16C)  "Most supported explanation?" — LLM interprets stored evidence only
```

| Stage | Command | Schedule (10-min interval) | Reads | External calls |
|---|---|---|---|---|
| Detection | `memecoins:detect-pumps` | `5,15,25,…` | stored snapshots | none |
| Evidence | `memecoins:collect-evidence` | `8,18,28,…` | snapshots, tokens, pump events | GDELT news only |
| Explanation | `memecoins:explain-pumps` | `9,19,29,…` | one pump event + its ranked evidence | configured LLM provider only |

All three reuse the single `scheduler` container, each `withoutOverlapping(15)`.

## Step 16A — detection (summary)

`PumpDetectionService` compares, per recently-observed token, the latest
observation against the one ~60 min earlier over the recent snapshot window. It
requires a **≥ 50% market-cap OR ≥ 40% price** move **plus ≥ 2 confirming
signals** (MC / price / rolling-24h volume ratio / rolling-24h txn ratio), scores
`0–100` (deterministic strength, not a prediction), assigns `low/medium/high`
confidence, and creates or **merges** a `pump_events` row (one continuous pump =
one event). Timestamps are snapshot `observed_at` values — an "observed pump",
never tick-level. See the detailed section for thresholds, merging, the stale
sweep and query bounding.

## Step 16B — evidence

[`docs/evidence-engine.md`](evidence-engine.md). Timestamped facts around each
event (`MARKET` / `TOKEN_METADATA` / `ORIGIN` / `RELATED_TOKEN` / `NEWS`), stored
separately from interpretation, never asserting causality.

## Step 16C — AI explanation

[`docs/pump-explanation.md`](pump-explanation.md). The LLM interprets the stored
evidence into a structured `pump_explanations` row — every claim cites evidence
ids, causal language is rejected, `UNKNOWN` when evidence is thin. The read API
exposes it under `data.pump_intelligence` and never triggers generation.
