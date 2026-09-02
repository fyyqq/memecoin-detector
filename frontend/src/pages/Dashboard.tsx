import { useCallback, useEffect, useRef, useState } from 'react'
import { API_BASE_URL, fetchRecentlyCrossed, MemecoinApiError } from '../api/memecoins'
import { ChainFilter } from '../components/ChainFilter'
import { RecentlyCrossedSection } from '../components/RecentlyCrossedSection'
import { formatDateTime } from '../lib/format'
import type { RecentlyCrossedResponse } from '../types/memecoin'

const AUTO_REFRESH_MS = 60_000

export function Dashboard() {
  const [chain, setChain] = useState('')
  const [fetching, setFetching] = useState(false)

  const [crossed, setCrossed] = useState<RecentlyCrossedResponse | null>(null)
  const [crossedLoading, setCrossedLoading] = useState(true)
  const [crossedError, setCrossedError] = useState('')

  const crossedAbortRef = useRef<AbortController | null>(null)

  const message = (error: unknown, fallback: string) =>
    error instanceof MemecoinApiError ? error.message : fallback

  const loadCrossed = useCallback(async (nextChain: string) => {
    crossedAbortRef.current?.abort()
    const controller = new AbortController()
    crossedAbortRef.current = controller
    setFetching(true)
    setCrossedLoading(true)
    try {
      setCrossed(await fetchRecentlyCrossed({ chain: nextChain || undefined }, controller.signal))
      setCrossedError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setCrossedError(message(error, 'Unable to load recent crossings.'))
    } finally {
      if (crossedAbortRef.current === controller) {
        setCrossedLoading(false)
        setFetching(false)
      }
    }
  }, [])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadCrossed(chain)
  }, [chain, loadCrossed])

  // Gentle auto-refresh — every minute.
  useEffect(() => {
    const timer = window.setInterval(() => {
      void loadCrossed(chain)
    }, AUTO_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [chain, loadCrossed])

  useEffect(
    () => () => {
      crossedAbortRef.current?.abort()
    },
    [],
  )

  const recentDays = crossed?.meta.days ?? 30
  const retrievedAt = crossed?.meta.retrieved_at

  return (
    <main className="app">
      <header className="app-header">
        <div>
          <h1>Memecoin Detector</h1>
          <p className="subtitle">Newly-launched memecoins, filtered for market quality &amp; risk</p>
        </div>
        <div className="controls">
          <ChainFilter value={chain} onChange={setChain} disabled={fetching} />
          <button type="button" onClick={() => void loadCrossed(chain)} disabled={fetching}>
            {fetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>

      <RecentlyCrossedSection
        rows={crossed?.data ?? []}
        days={recentDays}
        loading={crossedLoading}
        error={crossedError}
        onRetry={() => void loadCrossed(chain)}
      />

      <footer className="provenance">
        <p>
          Data source: <strong>DexScreener</strong> (documented APIs only)
          {retrievedAt && <>{' · '}Retrieved: {formatDateTime(retrievedAt)}</>}
        </p>
        <p className="muted">
          Market-cap figures are provider-reported. The dashboard reads persisted data from this
          app&rsquo;s API only (<code>{API_BASE_URL}/api/memecoins/recently-crossed</code>). It never
          calls DexScreener or any provider directly, and never opens a WebSocket.
        </p>
      </footer>
    </main>
  )
}
