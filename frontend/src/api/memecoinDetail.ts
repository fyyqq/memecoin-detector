import type { MemecoinDetailResponse } from '../types/memecoinDetail'
import { API_BASE_URL, MemecoinApiError } from './memecoins'

/** Thrown when the token is not in our database (HTTP 404). */
export class MemecoinNotFoundError extends MemecoinApiError {
  constructor() {
    super('Memecoin not found.')
    this.name = 'MemecoinNotFoundError'
  }
}

/**
 * Fetch one token's detail from the Laravel read API.
 *
 * Only ever talks to Laravel (`GET /api/memecoins/{chainId}/{tokenAddress}`) —
 * never DexScreener. Identity is chain + address; the symbol is never used.
 */
export async function fetchMemecoinDetail(
  chainId: string,
  tokenAddress: string,
  signal?: AbortSignal,
): Promise<MemecoinDetailResponse> {
  const url = `${API_BASE_URL}/api/memecoins/${encodeURIComponent(chainId)}/${encodeURIComponent(tokenAddress)}`

  let response: Response
  try {
    response = await fetch(url, { headers: { Accept: 'application/json' }, signal })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') throw cause
    throw new MemecoinApiError('Unable to reach the memecoin API.')
  }

  if (response.status === 404) {
    throw new MemecoinNotFoundError()
  }

  if (!response.ok) {
    throw new MemecoinApiError('Unable to load this memecoin.')
  }

  try {
    return (await response.json()) as MemecoinDetailResponse
  } catch {
    throw new MemecoinApiError('The memecoin API returned an unexpected response.')
  }
}
