/** One stored observation, as returned inside the token detail payload. */
export interface MarketSnapshotRow {
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

/** `GET /api/memecoins/{chainId}/{tokenAddress}` → `data`. */
export interface MemecoinDetail {
  id: number
  chain_id: string
  token_address: string
  name: string | null
  symbol: string | null

  /** From the latest MarketSnapshot — null if that observation lacked it. */
  current_market_cap: number | null
  /** Highest market cap THIS detector has captured (NOT a lifetime ATH). */
  observed_peak_market_cap: number | null
  observed_peak_market_cap_at: string | null

  /** Days since earliest DEX pool creation (not token deploy time). */
  age_days: number | null

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
  /** Not captured in Sprint 1 — always null for now. */
  pair_count: number | null

  earliest_pair_created_at: string | null
  first_observed_at: string | null
  last_observed_at: string | null

  data_source: string

  /** Bounded, newest-first window of recent observations. */
  snapshots: MarketSnapshotRow[]
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
