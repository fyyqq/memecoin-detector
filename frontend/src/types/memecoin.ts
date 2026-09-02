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

// --- Post-30-Day Memecoins -------------------------------------------------

/** Sort keys accepted by `GET /api/memecoins/post-30-day`. */
export type PostThirtyDaySort =
  | 'market_cap'
  | 'volume'
  | 'peak_market_cap'
  | 'age'
  | 'liquidity'
  | 'holders'

export type SortDirection = 'asc' | 'desc'

/** One row of `GET /api/memecoins/post-30-day`. */
export interface PostThirtyDayRow {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null
  age_days: number | null
  current_market_cap: number | null
  observed_peak_market_cap: number | null
  peak_market_cap: number | null
  volume_h24: number | null
  liquidity_usd: number | null
  holder_count: number | null
  /** Current risk level — `null` until the token has been screened. */
  risk_level: RiskLevel | null
  risk_score: number | null
  risk_status: string
  /** ACTIVE = current MC ≥ $5M · COOLED = current MC < $5M (never alarmist). */
  status: 'ACTIVE' | 'COOLED'
  /** When the token was first approved by the Recently Crossed flow. */
  approved_at: string | null
  crossed_at: string | null
  crossing_type: CrossingType | null
  /** Days between pool creation and the $5M crossing, when both are known. */
  days_to_cross: number | null
  last_observed_at: string | null
}

export interface PostThirtyDayResponse {
  data: PostThirtyDayRow[]
  meta: {
    count: number
    retrieved_at: string
    source: string
    sort: PostThirtyDaySort
    direction: SortDirection
    age_threshold_days: number
    sorts: PostThirtyDaySort[]
    note: string
  }
}
