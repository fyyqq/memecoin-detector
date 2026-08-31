import type { QualificationStatus } from './memecoinDetail'

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
  filters: {
    max_age_days: number
    observed_peak_market_cap_min_usd: number
  }
}

export interface MemecoinListResponse {
  data: Memecoin[]
  meta: MemecoinListMeta
}

export interface MemecoinQuery {
  chain?: string
  limit?: number
}
