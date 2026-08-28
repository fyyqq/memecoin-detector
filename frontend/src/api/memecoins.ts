import type { MemecoinListResponse, MemecoinQuery } from '../types/memecoin'

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

  const url = `${API_BASE_URL}/api/memecoins${params.toString() ? `?${params}` : ''}`

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
    return (await response.json()) as MemecoinListResponse
  } catch {
    throw new MemecoinApiError('The memecoin API returned an unexpected response.')
  }
}

export { API_BASE_URL }
