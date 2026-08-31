/** Evidence status from the historical qualification engine. */
export type QualificationStatus =
  | 'CURRENT_OBSERVATION'
  | 'HISTORICAL_VERIFIED'
  | 'HISTORICAL_ESTIMATE'
  | 'UNKNOWN'

/** `data.qualification` — MAIN-LIST qualification (verified/observed market cap in $5M–$200M). */
export interface MemecoinQualification {
  status: QualificationStatus
  /** true only for CURRENT_OBSERVATION / HISTORICAL_VERIFIED with a peak in [$5M, $200M]. */
  qualified: boolean
  /** The qualifying VERIFIED/OBSERVED market cap. Null for HISTORICAL_ESTIMATE / UNKNOWN / above-ceiling — never an FDV estimate. */
  peak_value: number | null
  peak_at: string | null
  /** dexscreener | coingecko | null */
  source: string | null
  /** current_market_cap | market_cap | null */
  basis: string | null
  /** high | medium | low | null */
  confidence: string | null
  /** "peak_above_ceiling" when a verified/observed peak cleared $5M but exceeds $200M; else null. */
  ineligible_reason: string | null
}

/** The kind of "$5M crossing" recorded for a token (Step 20). */
export type CrossingType = 'CURRENT_OBSERVATION' | 'HISTORICAL_VERIFIED'

/** One recorded "$5M crossing" in `data.qualification_timeline.events`. */
export interface QualificationCrossingEvent {
  type: CrossingType
  crossed_at: string | null
  /** dexscreener | coingecko | null */
  source: string | null
  market_cap_value: number | null
}

/**
 * `data.qualification_timeline` (Step 20) — WHEN / HOW the token first cleared
 * $5M. All-null / empty `events` when no crossing has been recorded.
 */
export interface MemecoinQualificationTimeline {
  /** Representative crossing (HISTORICAL_VERIFIED over CURRENT_OBSERVATION). */
  crossed_at: string | null
  crossing_type: CrossingType | null
  /** dexscreener | coingecko | null */
  crossing_source: string | null
  crossing_market_cap_value: number | null
  /** crossed_at within the configured recent window (default 48h). */
  recently_crossed: boolean
  /** latest snapshot MC is below $5M (the token stays qualified anyway). */
  currently_below_threshold: boolean | null
  threshold_usd: number
  events: QualificationCrossingEvent[]
}

/**
 * `data.historical_estimate` — a GeckoTerminal FDV-basis estimate. Informational
 * ONLY. Never a market cap, never qualifies the token for the main list. Null
 * unless a HISTORICAL_ESTIMATE evidence row exists.
 */
export interface MemecoinHistoricalEstimate {
  estimated_fdv_usd: number | null
  /** geckoterminal */
  estimate_source: string | null
  /** fdv_total_supply */
  estimate_basis: string | null
  /** high | medium | low | null */
  estimate_confidence: string | null
  estimate_at: string | null
  note: string
}

/** `data.observed` — figures from OUR OWN snapshots. */
export interface MemecoinObserved {
  /** Highest market cap THIS detector has captured (NOT a lifetime ATH). */
  peak_market_cap: number | null
  peak_at: string | null
  first_observed_at: string | null
  last_observed_at: string | null
}

/** `data.latest` — the most recent stored MarketSnapshot. */
export interface MemecoinLatestSnapshot {
  market_cap: number | null
  price_usd: number | null
  fdv: number | null
  liquidity_usd: number | null
  volume_h24: number | null
  price_change_h24: number | null
  txns_h24: number | null
  buys_h24: number | null
  sells_h24: number | null
  primary_dex_id: string | null
  primary_pair_address: string | null
  observed_at: string | null
}

/** One row in `data.snapshots` — a stored observation. */
export interface MemecoinSnapshot {
  observed_at: string | null
  price_usd: number | null
  market_cap: number | null
  fdv: number | null
  liquidity_usd: number | null
  volume_h24: number | null
  price_change_h24: number | null
  txns_h24: number | null
  buys_h24: number | null
  sells_h24: number | null
}

export interface MemecoinPair {
  earliest_pair_created_at: string | null
  /** Not captured in Sprint 1 — always null for now. */
  pair_count: number | null
}

/** One of the fixed AI catalyst categories (or UNKNOWN). Never free text. */
export type PumpCatalyst =
  | 'OFFICIAL_ANNOUNCEMENT'
  | 'CELEBRITY_INFLUENCER'
  | 'NARRATIVE_ROTATION'
  | 'EXCHANGE_LISTING'
  | 'COMMUNITY_TAKEOVER'
  | 'AIRDROP_BUYBACK'
  | 'WHALE_ACTIVITY'
  | 'RELATED_TOKEN_SPILLOVER'
  | 'LIQUIDITY_EVENT'
  | 'MARKET_ACTIVITY'
  | 'UNKNOWN'

export type PumpExplanationStatus = 'pending' | 'completed' | 'failed'

/** One evidence record the AI explanation cites, resolved from the DB. */
export interface CitedEvidence {
  id: number
  category: string
  source: string
  source_url: string | null
  title: string | null
  summary: string
  observed_at: string | null
  published_at: string | null
  relevance_score: number
  confidence: string
}

export interface PumpExplanationEvidenceLine {
  statement: string
  evidence_ids: number[]
}

export interface PumpExplanationSecondarySignal {
  label: string
  statement: string
  evidence_ids: number[]
}

/** Human-readable block DERIVED from the structured result (never hardcoded). */
export interface PumpExplanationPresented {
  question: string
  headline: string
  catalyst: PumpCatalyst
  catalyst_label: string
  summary: string
  evidence_lines: PumpExplanationEvidenceLine[]
  secondary_signals: PumpExplanationSecondarySignal[]
  confidence: string
  confidence_label: string
  caveats: string[]
  unknowns: string[]
}

export interface PumpExplanation {
  status: PumpExplanationStatus
  summary: string | null
  primary_catalyst: PumpCatalyst | null
  secondary_signals: Array<{ type: PumpCatalyst; statement: string; evidence_ids: number[] }>
  evidence: Array<{ evidence_id: number; statement: string }>
  confidence: string | null
  caveats: string[]
  unknowns: string[]
  model_provider: string | null
  model_name: string | null
  generated_at: string | null
  presented: PumpExplanationPresented | null
  cited_evidence: CitedEvidence[]
}

export interface PumpIntelligenceEvent {
  id: number
  started_at: string | null
  peak_at: string | null
  ended_at: string | null
  status: string
  start_market_cap: number | null
  peak_market_cap: number | null
  market_cap_change_pct: number | null
  price_change_pct: number | null
  volume_h24_change_ratio: number | null
  txns_h24_change_ratio: number | null
  duration_minutes: number | null
  detection_score: number | null
  detection_confidence: string
  explanation: PumpExplanation
}

export interface MemecoinPumpIntelligence {
  events: PumpIntelligenceEvent[]
}

export interface MemecoinProvenance {
  data_source: string
  last_observed_at: string | null
  historical_qualification_source: string | null
  observed_peak_note: string
  /** Present (non-null) only when the qualification is an FDV-basis estimate. */
  historical_estimate_note: string | null
}

/** `GET /api/memecoins/{chainId}/{tokenAddress}` → `data`. */
export interface MemecoinDetail {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null
  /** Days since earliest DEX pool creation (not token deploy time). */
  age_days: number | null

  qualification: MemecoinQualification
  /** "$5M crossing" timeline (Step 20). */
  qualification_timeline: MemecoinQualificationTimeline
  /** FDV-basis estimate — informational only. Null unless one exists. */
  historical_estimate: MemecoinHistoricalEstimate | null
  observed: MemecoinObserved
  latest: MemecoinLatestSnapshot
  pair: MemecoinPair
  /** Bounded, newest-first window of recent observations (≤ 50). */
  snapshots: MemecoinSnapshot[]
  /** Recent pump events + their persisted, evidence-backed AI explanations. */
  pump_intelligence: MemecoinPumpIntelligence
  provenance: MemecoinProvenance
}

export interface MemecoinDetailMeta {
  retrieved_at: string
  recent_snapshot_limit: number
  observed_peak_note: string
}

export interface MemecoinDetailResponse {
  data: MemecoinDetail
  meta: MemecoinDetailMeta
}
