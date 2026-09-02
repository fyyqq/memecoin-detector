import type { MonthlyChampionsResponse, RecentlyCrossedResponse } from '../types/memecoin'

const API_BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8010').replace(/\/$/, '')

export class MemecoinApiError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'MemecoinApiError'
  }
}

/**
 * Fetch the "Recently Crossed $5M" feed. Laravel-only, PostgreSQL-only — never
 * DexScreener. `hours` widens/narrows the window (server max 168); `chain`
 * filters to one real DexScreener chain id.
 */
export async function fetchRecentlyCrossed(
  { chain, hours }: { chain?: string; hours?: number } = {},
  signal?: AbortSignal,
): Promise<RecentlyCrossedResponse> {
  const params = new URLSearchParams()
  if (hours) params.set('hours', String(hours))
  if (chain) params.set('chain', chain)
  const url = `${API_BASE_URL}/api/memecoins/recently-crossed${
    params.toString() ? `?${params}` : ''
  }`

  return getJson<RecentlyCrossedResponse>(url, signal)
}

/**
 * Fetch the "Monthly Top Memecoins" grid for a year. Laravel-only — reads
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
