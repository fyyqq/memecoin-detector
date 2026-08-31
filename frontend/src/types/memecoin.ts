import type { QualificationStatus } from './memecoinDetail'

/** The kind of "$5M crossing" recorded for a token (Step 20). */
export type CrossingType = 'CURRENT_OBSERVATION' | 'HISTORICAL_VERIFIED'

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
