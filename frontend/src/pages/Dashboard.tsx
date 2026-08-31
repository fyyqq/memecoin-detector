import { useCallback, useEffect, useRef, useState } from 'react'
import {
  API_BASE_URL,
  fetchMemecoins,
  fetchRecentlyCrossed,
  MemecoinApiError,
} from '../api/memecoins'
import { ChainFilter } from '../components/ChainFilter'
import { MemecoinTable } from '../components/MemecoinTable'
import { RecentlyCrossedSection } from '../components/RecentlyCrossedSection'
import { formatDateTime, formatUsd } from '../lib/format'
import type { MemecoinListResponse, MemecoinSort, RecentlyCrossedResponse } from '../types/memecoin'

type Status = 'loading' | 'ready' | 'error'

const AUTO_REFRESH_MS = 60_000

// The main leaderboard keeps its stable peak-ranked order by default — the
// "Recently Crossed $5M" section above it already serves the recency view, and
// re-ordering the leaderboard on every crossing would disorient regulars.
const DEFAULT_SORT: MemecoinSort = 'peak_market_cap'

export function Dashboard() {
  const [chain, setChain] = useState('')
  const [sort, setSort] = useState<MemecoinSort>(DEFAULT_SORT)
  const [status, setStatus] = useState<Status>('loading')
  const [fetching, setFetching] = useState(false)
  const [result, setResult] = useState<MemecoinListResponse | null>(null)
  const [errorMessage, setErrorMessage] = useState('')

  const [crossed, setCrossed] = useState<RecentlyCrossedResponse | null>(null)
  const [crossedLoading, setCrossedLoading] = useState(true)
  const [crossedError, setCrossedError] = useState('')

  const abortRef = useRef<AbortController | null>(null)
  const crossedAbortRef = useRef<AbortController | null>(null)

  const load = useCallback(async (nextChain: string, nextSort: MemecoinSort) => {
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    setFetching(true)

    try {
      const data = await fetchMemecoins(
        { chain: nextChain || undefined, sort: nextSort },
        controller.signal,
      )
      setResult(data)
      setErrorMessage('')
      setStatus('ready')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setErrorMessage(
        error instanceof MemecoinApiError ? error.message : 'Unable to load memecoin data.',
      )
      setStatus('error')
    } finally {
      if (abortRef.current === controller) setFetching(false)
    }
  }, [])

  const loadCrossed = useCallback(async () => {
    crossedAbortRef.current?.abort()
    const controller = new AbortController()
    crossedAbortRef.current = controller
    setCrossedLoading(true)

    try {
      const data = await fetchRecentlyCrossed(undefined, controller.signal)
      setCrossed(data)
      setCrossedError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setCrossedError(
        error instanceof MemecoinApiError ? error.message : 'Unable to load recent crossings.',
      )
    } finally {
      if (crossedAbortRef.current === controller) setCrossedLoading(false)
    }
  }, [])

  // Sync the UI with the API on mount and whenever the filter / sort changes.
  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void load(chain, sort)
  }, [chain, sort, load])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadCrossed()
  }, [loadCrossed])

  // Gentle auto-refresh — one call per minute per feed, no aggressive polling.
  useEffect(() => {
    const timer = window.setInterval(() => {
      void load(chain, sort)
      void loadCrossed()
    }, AUTO_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [chain, sort, load, loadCrossed])

  useEffect(
    () => () => {
      abortRef.current?.abort()
      crossedAbortRef.current?.abort()
    },
    [],
  )

  const rows = result?.data ?? []
  const meta = result?.meta
  const peakThreshold = meta ? formatUsd(meta.filters.observed_peak_market_cap_min_usd) : '$5M'
  const maxAge = meta?.filters.max_age_days ?? 30
  const qualificationText = `Qualification: age ≤ ${maxAge} days and a verified/observed market cap peak in ${peakThreshold}–$200M.`
  const recentHours = crossed?.meta.hours ?? meta?.recent_crossing_hours ?? 48

  const refreshAll = () => {
    void load(chain, sort)
    void loadCrossed()
  }

  return (
    <main className="app">
      <header className="app-header">
        <div>
          <h1>Memecoin Detector</h1>
          <p className="subtitle">$5M crossings &amp; 30-Day Leaders</p>
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
        onRetry={() => void loadCrossed()}
      />

      <section className="qualified-list">
        <div className="section-heading">
          <h2>30-Day Qualified Memecoins</h2>
          <label className="sort-control">
            Sort
            <select
              value={sort}
              onChange={(event) => setSort(event.target.value as MemecoinSort)}
              disabled={fetching}
            >
              <option value="peak_market_cap">Peak market cap</option>
              <option value="recent_crossing">Recent crossing</option>
            </select>
          </label>
        </div>

        <p className="qualification">{qualificationText}</p>

        {status === 'loading' && <p className="state">Loading…</p>}

        {status === 'error' && (
          <div className="state state-error">
            <p>{errorMessage || 'Unable to load memecoin data.'}</p>
            <button type="button" onClick={() => void load(chain, sort)}>
              Try again
            </button>
          </div>
        )}

        {status === 'ready' && rows.length === 0 && (
          <div className="state">
            <p>No qualified memecoins observed yet.</p>
            <p className="muted">{qualificationText}</p>
          </div>
        )}

        {status === 'ready' && rows.length > 0 && <MemecoinTable rows={rows} />}
      </section>

      <footer className="provenance">
        <p>
          Data source: <strong>DexScreener</strong>
          {' · '}Last observed: {latestObservation(rows) ?? '—'}
          {meta && <>{' · '}Retrieved: {formatDateTime(meta.retrieved_at)}</>}
        </p>
        <p className="muted">
          Observed peak reflects the highest market cap captured by this detector, not
          guaranteed lifetime history. A token below $5M now can still be listed if it
          previously crossed the threshold.
        </p>
        <p className="muted">
          The dashboard reads persisted observations from this app&rsquo;s API
          (<code>{API_BASE_URL}/api/memecoins</code> and{' '}
          <code>/api/memecoins/recently-crossed</code>). It never calls DexScreener directly.
        </p>
      </footer>
    </main>
  )
}

function latestObservation(rows: Array<{ last_observed_at: string | null }>): string | null {
  const times = rows
    .map((row) => row.last_observed_at)
    .filter((value): value is string => Boolean(value))
    .sort()
  const latest = times.at(-1)
  return latest ? formatDateTime(latest) : null
}
