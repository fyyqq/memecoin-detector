/** The kind of "$5M crossing" recorded for a token (Step 20). */
export type CrossingType = 'CURRENT_OBSERVATION' | 'HISTORICAL_VERIFIED'

/**
 * Step 24 risk level. Never "SAFE". `RISK UNKNOWN` ("insufficient security
 * data") is distinct from HIGH. Shown on token detail pages.
 */
export type RiskLevel = 'LOWER' | 'MEDIUM' | 'HIGH' | 'CRITICAL' | 'UNKNOWN'

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
    /** Crossing window in days (default 30). */
    days: number
    count: number
    retrieved_at: string
    source: string
    note: string
  }
}

// --- Monthly Top Memecoins (Step 25 — Top 3) --------------------------------

/**
 * The five fixed MONTHLY display buckets. `other` = every non-core chain.
 * This is NOT the header chain filter (which uses real DexScreener chain ids).
 */
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
 * Per-bucket status (Step 25, Top 3):
 *   provisional          — current month, entries may still change
 *   finalized            — completed month with defensible ranked entries
 *                          (an entry may carry `confidence: low` where thin)
 *   no_verified_result   — completed month, no defensible candidate, entries: []
 *   future               — the month has not happened yet, entries: []
 */
export type MonthlyBucketStatus =
  | 'provisional'
  | 'finalized'
  | 'no_verified_result'
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

/** One research source behind a historically-backfilled entry (Step 25). */
export interface MonthlySourceEvidence {
  name: string
  url: string | null
  claim: string
  published_at: string | null
  credibility?: string
}

export interface MonthlyChampionPerformance {
  /** Transparent 0–100 participation score. NOT a prediction of returns. */
  score: number | null
  /** Monthly-max holder count — `null` means UNKNOWN (never shown as a number). */
  holder_count: number | null
  /** Representative monthly volume in USD. */
  monthly_volume: number | null
  /** Month-peak OBSERVED / VERIFIED market cap (never FDV, never current). */
  market_cap: number | null
  holder_strength: number | null
  volume_strength: number | null
  market_cap_strength: number | null
  /** Info-only context — never part of the score or the ordering. */
  market_cap_growth_pct: number | null
  peak_expansion_ratio: number | null
  observation_coverage_ratio: number | null
}

export interface MonthlyChampionToken {
  id: number | null
  symbol: string | null
  name: string | null
  /** The token's real chain. */
  chain_id: string
  /** The display bucket (may be "other"). */
  chain_bucket: ChainBucket
  token_address: string
  image_url: string | null
}

/** One ranked entry within a bucket (rank 1–3). */
export interface MonthlyChampionEntry {
  rank: number
  token: MonthlyChampionToken | null
  performance: MonthlyChampionPerformance
  source_type: MonthlySourceType | null
  source_reference: string | null
  /** Research provenance for a historically-backfilled entry. `[]` otherwise. */
  source_evidence: MonthlySourceEvidence[]
  /** The 30-day trading-age window could not be established from evidence. */
  age_uncertain: boolean
  confidence: 'high' | 'medium' | 'low' | null
  finalized_at: string | null
  computed_at: string | null
}

export interface MonthlyChampionBucket {
  chain_bucket: ChainBucket
  status: MonthlyBucketStatus
  /** 0–3 ranked entries. Empty for `future` / `no_verified_result`. */
  entries: MonthlyChampionEntry[]
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
    top_n: number
    weights: { holder: number; volume: number; market_cap: number }
    retrieved_at: string
    source: string
    selection_note: string
  }
}
