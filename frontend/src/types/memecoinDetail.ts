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

// --- Step 21: Token Narrative Intelligence -------------------------------------

export type NarrativeSectionStatus = 'pending' | 'completed' | 'partial' | 'failed'

export type NarrativeOriginType =
  | 'COMMUNITY_MEME'
  | 'INTERNET_MEME'
  | 'CELEBRITY_MEME'
  | 'POLITICAL_MEME'
  | 'CULTURAL_REFERENCE'
  | 'VIRAL_EVENT'
  | 'ANIMAL_MEME'
  | 'NARRATIVE_TOKEN'
  | 'UTILITY_PLUS_MEME'
  | 'UNKNOWN'

export type NarrativeTimelineType =
  | 'MEME_ORIGIN'
  | 'LAUNCH'
  | 'MEDIA_ATTENTION'
  | 'SOCIAL_ATTENTION'
  | 'CELEBRITY_ATTENTION'
  | 'EXCHANGE_LISTING'
  | 'NARRATIVE_EVENT'
  | 'RELATED_TOKEN'
  | 'COMMUNITY_EVENT'
  | 'MARKET_ACTIVITY'
  | 'OTHER'

/** One persisted research source the synthesis cites by `id`. */
export interface NarrativeSource {
  id: number
  section: 'origin' | 'popularity'
  source_type: 'official' | 'news' | 'social' | 'market' | 'community' | 'reference'
  source_name: string
  title: string | null
  source_url: string | null
  published_at: string | null
  confidence: 'low' | 'medium' | 'high'
  claim: string
  relevance_score: number
}

export interface NarrativeSupportingFact {
  statement: string
  source_ids: number[]
}

export interface NarrativeTimelineEntry {
  date: string | null
  title: string
  description: string
  type: NarrativeTimelineType
  source_ids: number[]
  confidence: 'low' | 'medium' | 'high'
}

/** `data.token_narrative.origin` — "Why was this coin created?" */
export interface NarrativeOriginSection {
  status: NarrativeSectionStatus
  headline: string | null
  summary: string | null
  origin_type?: NarrativeOriginType | null
  supporting_facts?: NarrativeSupportingFact[]
  confidence: 'low' | 'medium' | 'high' | null
  caveats: string[]
  unknowns: string[]
}

/** `data.token_narrative.popularity` — "Why did this coin become popular?" */
export interface NarrativePopularitySection {
  status: NarrativeSectionStatus
  headline: string | null
  summary: string | null
  timeline?: NarrativeTimelineEntry[]
  dominant_factors?: string[]
  confidence: 'low' | 'medium' | 'high' | null
  caveats: string[]
  unknowns: string[]
}

/**
 * `data.token_narrative` — token-level, evidence-grounded narrative intelligence.
 * The read API NEVER triggers research; `status: "pending"` when no report
 * exists. Provider error details are never exposed.
 */
export interface TokenNarrative {
  status: NarrativeSectionStatus
  generated_at?: string | null
  model_provider?: string | null
  research_providers_used?: string[]
  origin: NarrativeOriginSection
  popularity: NarrativePopularitySection
  sources: NarrativeSource[]
}

// --- Step 24: Risk Assessment ------------------------------------------------

export type RiskLevel = 'LOWER' | 'MEDIUM' | 'HIGH' | 'CRITICAL' | 'UNKNOWN'
export type RiskSignalState = 'MEASURED' | 'BAD' | 'UNKNOWN' | 'NOT_AVAILABLE'
export type RiskScreeningStatus = 'pending' | 'completed' | 'partial' | 'failed'

export type RiskSignalGroup =
  | 'contract_security'
  | 'exit_safety'
  | 'holder_distribution'
  | 'liquidity'
  | 'pump_dump'
  | 'market_structure'
  | 'age'

export interface RiskSignal {
  group: RiskSignalGroup
  key: string
  state: RiskSignalState
  value: string | null
  unit: string | null
  severity: 'none' | 'low' | 'medium' | 'high' | 'critical'
  source: string | null
  source_checked_at: string | null
  explanation: string | null
}

/**
 * `data.risk_assessment` (Step 24) — deterministic risk screening. Read-only;
 * NEVER triggers screening. `status: "pending"` when not yet screened. Never
 * uses the word "safe".
 */
export interface RiskAssessment {
  status: RiskScreeningStatus
  risk_level: RiskLevel | null
  risk_score: number | null
  data_completeness: number | null
  screened_at: string | null
  provider_version?: string | null
  hard_override_signal: string | null
  main_list_eligible: boolean
  signals: RiskSignal[]
  disclaimer: string
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
  /** Token-level origin + popularity narrative intelligence (Step 21). */
  token_narrative: TokenNarrative
  /** Deterministic risk screening (Step 24). */
  risk_assessment: RiskAssessment
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
