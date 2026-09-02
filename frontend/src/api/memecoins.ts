import type {
  PostThirtyDayResponse,
  PostThirtyDaySort,
  RecentlyCrossedResponse,
  SortDirection,
} from '../types/memecoin'

const API_BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8010').replace(/\/$/, '')

export class MemecoinApiError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'MemecoinApiError'
  }
}

/**
 * Fetch the "Recently Crossed $5M" feed. Laravel-only, PostgreSQL-only — never
 * DexScreener. Fixed 30-day crossing window + quality gates on the server.
 * `chain` filters to one real DexScreener chain id.
 */
export async function fetchRecentlyCrossed(
  { chain }: { chain?: string } = {},
  signal?: AbortSignal,
): Promise<RecentlyCrossedResponse> {
  const params = new URLSearchParams()
  if (chain) params.set('chain', chain)
  const url = `${API_BASE_URL}/api/memecoins/recently-crossed${
    params.toString() ? `?${params}` : ''
  }`

  return getJson<RecentlyCrossedResponse>(url, signal)
}

/**
 * Fetch the "Post-30-Day Memecoins" feed — memecoins previously approved by the
 * Recently Crossed flow whose pool is now older than 30 days. Laravel-only,
 * PostgreSQL-only. `chain` filters to one real chain; `sort` / `direction` are
 * applied by the backend.
 */
export async function fetchPostThirtyDay(
  {
    chain,
    sort,
    direction,
  }: { chain?: string; sort?: PostThirtyDaySort; direction?: SortDirection } = {},
  signal?: AbortSignal,
): Promise<PostThirtyDayResponse> {
  const params = new URLSearchParams()
  if (chain) params.set('chain', chain)
  if (sort) params.set('sort', sort)
  if (direction) params.set('direction', direction)
  const url = `${API_BASE_URL}/api/memecoins/post-30-day${params.toString() ? `?${params}` : ''}`

  return getJson<PostThirtyDayResponse>(url, signal)
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
