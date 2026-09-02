import { useCallback, useEffect, useRef, useState } from 'react'
import {
  API_BASE_URL,
  fetchMonthlyChampions,
  fetchRecentlyCrossed,
  MemecoinApiError,
} from '../api/memecoins'
import { ChainFilter } from '../components/ChainFilter'
import { MonthlyChampions } from '../components/MonthlyChampions'
import { RecentlyCrossedSection } from '../components/RecentlyCrossedSection'
import { formatDateTime } from '../lib/format'
import type { MonthlyChampionsResponse, RecentlyCrossedResponse } from '../types/memecoin'

const AUTO_REFRESH_MS = 60_000

export function Dashboard() {
  const [chain, setChain] = useState('')
  const [fetching, setFetching] = useState(false)

  const [crossed, setCrossed] = useState<RecentlyCrossedResponse | null>(null)
  const [crossedLoading, setCrossedLoading] = useState(true)
  const [crossedError, setCrossedError] = useState('')

  const [champions, setChampions] = useState<MonthlyChampionsResponse | null>(null)
  const [championsLoading, setChampionsLoading] = useState(true)
  const [championsError, setChampionsError] = useState('')

  const crossedAbortRef = useRef<AbortController | null>(null)
  const championsAbortRef = useRef<AbortController | null>(null)

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

  const loadChampions = useCallback(async () => {
    championsAbortRef.current?.abort()
    const controller = new AbortController()
    championsAbortRef.current = controller
    setChampionsLoading(true)
    try {
      setChampions(await fetchMonthlyChampions(undefined, controller.signal))
      setChampionsError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setChampionsError(message(error, 'Unable to load monthly top memecoins.'))
    } finally {
      if (championsAbortRef.current === controller) setChampionsLoading(false)
    }
  }, [])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadCrossed(chain)
  }, [chain, loadCrossed])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadChampions()
  }, [loadChampions])

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
      championsAbortRef.current?.abort()
    },
    [],
  )

  const recentHours = crossed?.meta.hours ?? 48
  const retrievedAt = crossed?.meta.retrieved_at

  const refreshAll = () => {
    void loadCrossed(chain)
    void loadChampions()
  }

  return (
    <main className="app">
      <header className="app-header">
        <div>
          <h1>Memecoin Detector</h1>
          <p className="subtitle">Newly-launched memecoins, filtered for market quality &amp; risk</p>
        </div>
        <div className="controls">
          <ChainFilter value={chain} onChange={setChain} disabled={fetching} />
          <button type="button" onClick={refreshAll} disabled={fetching}>
            {fetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>

      <RecentlyCrossedSection
        rows={crossed?.data ?? []}
        hours={recentHours}
        loading={crossedLoading}
        error={crossedError}
        onRetry={() => void loadCrossed(chain)}
      />

      <MonthlyChampions
        months={champions?.data ?? []}
        year={champions?.meta.year ?? new Date().getUTCFullYear()}
        loading={championsLoading}
        error={championsError}
        onRetry={() => void loadChampions()}
      />

      <footer className="provenance">
        <p>
          Data source: <strong>DexScreener</strong> (documented APIs only)
          {retrievedAt && <>{' · '}Retrieved: {formatDateTime(retrievedAt)}</>}
        </p>
        <p className="muted">
          Market-cap figures are provider-reported. The dashboard reads persisted data from this
          app&rsquo;s API only (<code>{API_BASE_URL}/api/memecoins/recently-crossed</code>,{' '}
          <code>/monthly-champions</code>). It never calls DexScreener or any provider directly, and
          never opens a WebSocket.
        </p>
      </footer>
    </main>
  )
}
