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
