import type {
  ChainActivityResponse,
  MemecoinListResponse,
  MemecoinQuery,
  MonthlyChampionsResponse,
  RecentlyCrossedResponse,
  RiskWatchResponse,
  Timeframe,
  TopVolumeResponse,
  TrendingHistoryResponse,
  TrendingResponse,
} from '../types/memecoin'

const API_BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8010').replace(/\/$/, '')

export class MemecoinApiError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'MemecoinApiError'
  }
}

/**
 * Fetch the qualified "30-Day Leaders" list from the Laravel read API.
 *
 * This only ever talks to Laravel (`GET /api/memecoins`) — never DexScreener,
 * and never the discovery endpoint.
 */
export async function fetchMemecoins(
  query: MemecoinQuery = {},
  signal?: AbortSignal,
): Promise<MemecoinListResponse> {
  const params = new URLSearchParams()
  if (query.chain) params.set('chain', query.chain)
  if (query.limit) params.set('limit', String(query.limit))
  if (query.sort) params.set('sort', query.sort)

  const url = `${API_BASE_URL}/api/memecoins${params.toString() ? `?${params}` : ''}`

  return getJson<MemecoinListResponse>(url, signal)
}

/**
 * Fetch the "Recently Crossed $5M" feed. Laravel-only, PostgreSQL-only — never
 * DexScreener. `hours` widens/narrows the window (server max 168).
 */
export async function fetchRecentlyCrossed(
  hours?: number,
  signal?: AbortSignal,
): Promise<RecentlyCrossedResponse> {
  const params = new URLSearchParams()
  if (hours) params.set('hours', String(hours))
  const url = `${API_BASE_URL}/api/memecoins/recently-crossed${
    params.toString() ? `?${params}` : ''
  }`

  return getJson<RecentlyCrossedResponse>(url, signal)
}

/**
 * Fetch the "Monthly Meme Champions" grid for a year. Laravel-only — reads
 * `monthly_rankings` only, never recomputes, never calls a provider. Always 12
 * entries (January … December).
 */
export async function fetchMonthlyChampions(
  year?: number,
  signal?: AbortSignal,
): Promise<MonthlyChampionsResponse> {
  const params = new URLSearchParams()
  if (year) params.set('year', String(year))
  const url = `${API_BASE_URL}/api/memecoins/monthly-champions${
    params.toString() ? `?${params}` : ''
  }`

  return getJson<MonthlyChampionsResponse>(url, signal)
}

/**
 * Fetch the "Risk Watch" feed (Step 24) — market-cap-qualified tokens that fail
 * the main-list risk screen. Laravel-only, PostgreSQL-only — never a security
 * provider. This is a risk filter, not a "safe to invest" signal.
 */
export async function fetchRiskWatch(
  chain?: string,
  signal?: AbortSignal,
): Promise<RiskWatchResponse> {
  const params = new URLSearchParams()
  if (chain) params.set('chain', chain)
  const url = `${API_BASE_URL}/api/memecoins/risk-watch${params.toString() ? `?${params}` : ''}`

  return getJson<RiskWatchResponse>(url, signal)
}

/**
 * Fetch "Tracked Trending" for a timeframe (6h / 24h). Laravel-only,
 * PostgreSQL-only — reads `trending_snapshots`, never DexScreener, never a
 * WebSocket. This is our transparent internal ranking, NOT DexScreener's
 * proprietary trendingScore.
 */
export async function fetchTrending(
  timeframe: Timeframe,
  chain?: string,
  signal?: AbortSignal,
): Promise<TrendingResponse> {
  const params = new URLSearchParams({ timeframe })
  if (chain) params.set('chain', chain)
  return getJson<TrendingResponse>(`${API_BASE_URL}/api/memecoins/trending?${params}`, signal)
}

/**
 * Fetch "Trending Yesterday" — reads `daily_trending_rankings` only. Never
 * recomputes from current state, never calls a provider.
 */
export async function fetchTrendingHistory(
  timeframe: Timeframe,
  date?: string,
  chain?: string,
  signal?: AbortSignal,
): Promise<TrendingHistoryResponse> {
  const params = new URLSearchParams({ timeframe })
  if (date) params.set('date', date)
  if (chain) params.set('chain', chain)
  return getJson<TrendingHistoryResponse>(
    `${API_BASE_URL}/api/memecoins/trending/history?${params}`,
    signal,
  )
}

/**
 * Fetch "Top 5 Volume by Chain" — REPORTED volume, integrity-gated. Not claimed
 * organic. Laravel-only, PostgreSQL-only.
 */
export async function fetchTopVolume(
  chain?: string,
  signal?: AbortSignal,
): Promise<TopVolumeResponse> {
  const params = new URLSearchParams()
  if (chain) params.set('chain', chain)
  const url = `${API_BASE_URL}/api/memecoins/top-volume${params.toString() ? `?${params}` : ''}`
  return getJson<TopVolumeResponse>(url, signal)
}

/**
 * Fetch "Chain Market Activity" — reads `daily_chain_activity` only. Reported
 * volume, deduplicated token-level representative-pair volume per bucket.
 */
export async function fetchChainActivity(signal?: AbortSignal): Promise<ChainActivityResponse> {
  return getJson<ChainActivityResponse>(`${API_BASE_URL}/api/memecoins/chain-activity`, signal)
}

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
  let response: Response
  try {
    response = await fetch(url, { headers: { Accept: 'application/json' }, signal })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') throw cause
    throw new MemecoinApiError('Unable to reach the memecoin API.')
  }

  if (!response.ok) {
    throw new MemecoinApiError('Unable to load memecoin data.')
  }

  try {
    return (await response.json()) as T
  } catch {
    throw new MemecoinApiError('The memecoin API returned an unexpected response.')
  }
}

export { API_BASE_URL }
