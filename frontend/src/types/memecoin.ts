import type { QualificationStatus } from './memecoinDetail'

/** The kind of "$5M crossing" recorded for a token (Step 20). */
export type CrossingType = 'CURRENT_OBSERVATION' | 'HISTORICAL_VERIFIED'

/**
 * Step 24 risk level. Never "SAFE" — LOWER / MEDIUM appear on the main list;
 * HIGH / CRITICAL / UNKNOWN are Risk Watch only. RISK UNKNOWN ("insufficient
 * security data") is distinct from HIGH.
 */
export type RiskLevel = 'LOWER' | 'MEDIUM' | 'HIGH' | 'CRITICAL' | 'UNKNOWN'

/** Tri-state (+ NOT_AVAILABLE) for one risk signal. */
export type RiskSignalState = 'MEASURED' | 'BAD' | 'UNKNOWN' | 'NOT_AVAILABLE'

/** Sort options for `GET /api/memecoins`. */
export type MemecoinSort = 'peak_market_cap' | 'recent_crossing'

/** One qualified memecoin row from `GET /api/memecoins`. */
export interface Memecoin {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null

  /** From the latest MarketSnapshot — may be null if that observation lacked it. */
  current_market_cap: number | null
  /** Highest market cap this detector has captured (NOT a lifetime ATH). */
  observed_peak_market_cap: number | null
  observed_peak_market_cap_at: string | null

  /** How this token qualifies (always one of the 3 qualifying statuses here). */
  qualification_status: QualificationStatus
  qualification_peak_value: number | null
  qualification_peak_at: string | null
  qualification_source: string | null
  qualification_basis: string | null

  /**
   * The "$5M crossing" (Step 20) — the representative event (HISTORICAL_VERIFIED
   * over CURRENT_OBSERVATION). `null` when no crossing has been recorded.
   */
  qualification_crossed_at: string | null
  qualification_crossing_type: CrossingType | null
  /** crossed_at within the configured recent window (default 48h). */
  recently_crossed: boolean

  /**
   * Step 24 — the risk screen this row passed. Every MAIN LIST row is LOWER or
   * MEDIUM. `risk_summary` is a list of concise pre-written phrases (never the
   * word "safe").
   */
  risk_level: RiskLevel | null
  risk_score: number | null
  data_completeness: number | null
  risk_summary: string[]

  /**
   * Trending Tracking — this token's latest "Tracked Trending" state. `null`
   * when it is not currently trending. NOT DexScreener's proprietary score.
   */
  trend: MemecoinTrend | null

  /** Days since earliest DEX pool creation (not token deploy time). */
  age_days: number | null

  liquidity_usd: number | null
  volume_h24: number | null

  primary_dex_id: string | null
  primary_pair_address: string | null

  data_source: string
  last_observed_at: string | null
}

export interface MemecoinListMeta {
  count: number
  retrieved_at: string
  sort: MemecoinSort
  recent_crossing_hours: number
  filters: {
    max_age_days: number
    observed_peak_market_cap_min_usd: number
    observed_peak_market_cap_max_usd?: number
  }
}

export interface MemecoinListResponse {
  data: Memecoin[]
  meta: MemecoinListMeta
}

export interface MemecoinQuery {
  chain?: string
  limit?: number
  sort?: MemecoinSort
}

/** One row of `GET /api/memecoins/recently-crossed`. */
export interface RecentlyCrossedRow {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null
  current_market_cap: number | null
  observed_peak_market_cap: number | null
  qualification_peak: number | null
  crossed_at: string
  crossing_type: CrossingType
  crossing_market_cap_value: number | null
  /** ACTIVE = current MC ≥ $5M · COOLED = current MC < $5M (never alarmist). */
  status: 'ACTIVE' | 'COOLED'
  age_days: number | null
  last_observed_at: string | null
}

export interface RecentlyCrossedResponse {
  data: RecentlyCrossedRow[]
  meta: {
    hours: number
    count: number
    retrieved_at: string
    source: string
    note: string
  }
}

// --- Step 24: Risk Watch ----------------------------------------------------

/** One failing risk signal on a Risk Watch row. Explanations are pre-written. */
export interface RiskWatchFailedSignal {
  signal: string
  group: string
  state: RiskSignalState
  value: string | null
  source: string | null
  severity: 'none' | 'low' | 'medium' | 'high' | 'critical'
  explanation: string | null
}

/** One row of `GET /api/memecoins/risk-watch` — qualified by market cap, failed the risk screen. */
export interface RiskWatchRow {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null
  current_mc: number | null
  peak_mc: number | null
  age_days: number | null
  risk_level: RiskLevel
  risk_score: number | null
  data_completeness: number | null
  screened_at: string | null
  /** Concise pre-written reason phrases — only what was actually measured. */
  reasons: string[]
  failed_signals: RiskWatchFailedSignal[]
}

export interface RiskWatchResponse {
  data: RiskWatchRow[]
  meta: {
    count: number
    retrieved_at: string
    source: string
    note: string
  }
}

// --- Step 22 (corrected): Monthly Top Memecoins -----------------------------

/** The five fixed display buckets. `other` = every non-core chain. */
export type ChainBucket = 'solana' | 'robinhood' | 'bsc' | 'base' | 'other'

export const CHAIN_BUCKETS: ChainBucket[] = ['solana', 'robinhood', 'bsc', 'base', 'other']

export const CHAIN_BUCKET_LABEL: Record<ChainBucket, string> = {
  solana: 'Solana',
  robinhood: 'Robinhood',
  bsc: 'BSC',
  base: 'Base',
  other: 'Other',
}

/**
 * Per-bucket status:
 *   provisional              — current month, may still change
 *   finalized                — past month, sufficient internal evidence
 *   best_supported_candidate — past month, a real token led but evidence is thin
 *   no_verified_champion     — past month, no defensible winner
 *   future                   — the month has not happened yet
 */
export type MonthlyBucketStatus =
  | 'provisional'
  | 'finalized'
  | 'best_supported_candidate'
  | 'no_verified_champion'
  | 'future'

/** Month-level status. */
export type MonthlyMonthStatus = 'provisional' | 'finalized' | 'future'

export type MonthlySourceType =
  | 'internal_observed'
  | 'exact_dexscreener_rank'
  | 'best_supported_historical_performer'
  | 'dexscreener'
  | 'web_research'
  | 'other_verified_source'

/** One research source behind a historically-backfilled champion (Step 25). */
export interface MonthlySourceEvidence {
  name: string
  url: string | null
  claim: string
  published_at: string | null
  credibility?: string
}

export interface MonthlyChampionPerformance {
  /** Transparent 0–100 performance score. NOT a prediction of returns. */
  score: number | null
  baseline_market_cap: number | null
  peak_market_cap: number | null
  market_cap_growth_pct: number | null
  peak_expansion_ratio: number | null
  activity_score: number | null
  observation_count: number | null
  observation_coverage_ratio: number | null
}

export interface MonthlyChampionBucket {
  chain_bucket: ChainBucket
  status: MonthlyBucketStatus
  token: {
    id: number
    symbol: string | null
    name: string | null
    /** The token's real chain. */
    chain_id: string
    /** The display bucket (may be "other"). */
    chain_bucket: ChainBucket
    token_address: string
    image_url: string | null
  } | null
  performance: MonthlyChampionPerformance | null
  source_type: MonthlySourceType | null
  source_reference: string | null
  /** Research provenance for a historically-backfilled champion. `[]` otherwise. */
  source_evidence: MonthlySourceEvidence[]
  /** The 30-day trading-age window could not be established from evidence. */
  age_uncertain: boolean
  confidence: 'high' | 'medium' | 'low' | null
  finalized_at: string | null
  computed_at: string | null
}

export interface MonthlyChampionMonth {
  year: number
  month: number
  month_name: string
  status: MonthlyMonthStatus
  /** ALWAYS all five buckets, in canonical order. Never omitted. */
  champions: Record<ChainBucket, MonthlyChampionBucket>
}

export interface MonthlyChampionsResponse {
  data: MonthlyChampionMonth[]
  meta: {
    year: number
    count: number
    current_year: number
    current_month: number
    buckets: ChainBucket[]
    retrieved_at: string
    source: string
    selection_note: string
  }
}

// --- Trending Tracking -----------------------------------------------------

/** 6H / 24H — the two timeframes we track. */
export type Timeframe = '6h' | '24h'

export const TIMEFRAMES: Timeframe[] = ['6h', '24h']

/** A token's latest "Tracked Trending" state, embedded on Main List / Risk Watch rows. */
export interface MemecoinTrend {
  tracked_trend_score_6h: number | null
  trend_rank_6h: number | null
  tracked_trend_score_24h: number | null
  trend_rank_24h: number | null
  captured_at?: string | null
  last_trending_at?: string | null
}

/** One row of `GET /api/memecoins/trending` — a TOP-N eligible trending memecoin. */
export interface TrendingRow {
  /** 1-based position in this (filtered, top-N) response. */
  rank: number
  /** The stored rank among ALL eligible memecoins in this capture (context). */
  trend_rank: number
  tracked_trend_score: number
  trend_components: Record<string, number> | null
  trend_appearances: number
  timeframe: Timeframe
  is_memecoin_candidate: 'TRUE' | 'UNKNOWN' | 'FALSE' | null

  token_id: number | null
  chain_id: string
  chain_bucket: ChainBucket
  token_address: string
  pair_address: string | null
  dex_id: string | null
  symbol: string | null
  name: string | null

  age_days: number | null
  market_cap: number | null
  liquidity_usd: number | null
  volume_usd: number | null
  price_change_pct: number | null
  transaction_count: number | null

  trending_meta_slug: string | null
  trending_meta_name: string | null
  captured_at: string | null

  /** Risk is SEPARATE from trending — shown, never used to hide the row. */
  risk_level: RiskLevel | null
  risk_score: number | null
  risk_checked_at: string | null
  risk_check_stale: boolean
  is_tracked: boolean
  main_list_eligible: boolean
}

export interface TrendingResponse {
  data: TrendingRow[]
  meta: {
    timeframe: Timeframe
    chain: string | null
    count: number
    top_n: number
    top_max: number
    capture_bucket: number | null
    captured_at: string | null
    refresh_minutes: number
    retrieved_at: string
    source: string
    filters: {
      memecoin_only: boolean
      max_age_days: number
      min_current_market_cap: number
      max_current_market_cap: number
      volume_required: boolean
      liquidity_required: boolean
    }
    note: string
  }
}

/** One row of `GET /api/memecoins/trending/history`. */
export interface TrendingHistoryRow {
  best_rank: number
  best_score: number
  appearances: number
  timeframe: Timeframe
  chain_bucket: ChainBucket
  token_id: number | null
  chain_id: string
  token_address: string
  symbol: string | null
  name: string | null
  is_tracked: boolean
  peak_market_cap: number | null
  peak_volume: number | null
  peak_liquidity: number | null
  trending_meta_slug: string | null
  trending_meta_name: string | null
  first_seen_at: string | null
  last_seen_at: string | null
}

export interface TrendingHistoryResponse {
  data: TrendingHistoryRow[]
  meta: {
    date: string
    timeframe: Timeframe
    chain: string | null
    count: number
    is_yesterday: boolean
    retrieved_at: string
    source: string
    note: string
  }
}

/** `GET /api/memecoins/top-volume` — one chain bucket. */
export interface TopVolumeChain {
  chain_bucket: ChainBucket
  label: string
  tokens: Array<{
    token_id: number | null
    chain_id: string
    token_address: string
    symbol: string | null
    name: string | null
    reported_volume_usd: number | null
    liquidity_usd: number | null
    market_cap: number | null
    transaction_count: number | null
    risk_level: RiskLevel | null
    risk_checked_at: string | null
    risk_check_stale: boolean
    observed_at: string | null
  }>
}

export interface TopVolumeResponse {
  data: TopVolumeChain[]
  meta: {
    chain: string | null
    per_chain: number
    active_within_hours: number
    retrieved_at: string
    source: string
    note: string
  }
}

/** `GET /api/memecoins/chain-activity` — one chain bucket card. */
export interface ChainActivityCard {
  chain_bucket: ChainBucket
  label: string
  total_volume_usd: number | null
  total_liquidity_usd: number | null
  active_token_count: number
  top_token: {
    token_id: number | null
    token_address: string
    symbol: string | null
    reported_volume_usd: number | null
  } | null
  volume_change_pct: number | null
  computed_at: string | null
}

export interface ChainActivityResponse {
  data: ChainActivityCard[]
  meta: {
    date: string
    has_today: boolean
    retrieved_at: string
    source: string
    note: string
  }
}
