import { useCallback, useEffect, useRef, useState } from 'react'
import {
  API_BASE_URL,
  fetchChainActivity,
  fetchMemecoins,
  fetchMonthlyChampions,
  fetchRecentlyCrossed,
  fetchRiskWatch,
  fetchTopVolume,
  fetchTrending,
  fetchTrendingHistory,
  MemecoinApiError,
} from '../api/memecoins'
import { ChainActivity } from '../components/ChainActivity'
import { ChainFilter } from '../components/ChainFilter'
import { MemecoinTable } from '../components/MemecoinTable'
import { MonthlyChampions } from '../components/MonthlyChampions'
import { RecentlyCrossedSection } from '../components/RecentlyCrossedSection'
import { RiskWatchSection } from '../components/RiskWatchSection'
import { TopVolume } from '../components/TopVolume'
import { TrendingHistory } from '../components/TrendingHistory'
import { TrendingNow } from '../components/TrendingNow'
import { formatDateTime, formatUsd } from '../lib/format'
import type {
  ChainActivityResponse,
  MemecoinListResponse,
  MemecoinSort,
  MonthlyChampionsResponse,
  RecentlyCrossedResponse,
  RiskWatchResponse,
  Timeframe,
  TopVolumeResponse,
  TrendingHistoryResponse,
  TrendingResponse,
} from '../types/memecoin'

type Status = 'loading' | 'ready' | 'error'

const AUTO_REFRESH_MS = 60_000
const TRENDING_REFRESH_MS = 5 * 60_000

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

  const [champions, setChampions] = useState<MonthlyChampionsResponse | null>(null)
  const [championsLoading, setChampionsLoading] = useState(true)
  const [championsError, setChampionsError] = useState('')

  const [riskWatch, setRiskWatch] = useState<RiskWatchResponse | null>(null)
  const [riskWatchLoading, setRiskWatchLoading] = useState(true)
  const [riskWatchError, setRiskWatchError] = useState('')

  const [trendTf, setTrendTf] = useState<Timeframe>('6h')
  const [trending, setTrending] = useState<TrendingResponse | null>(null)
  const [trendingLoading, setTrendingLoading] = useState(true)
  const [trendingError, setTrendingError] = useState('')

  const [histTf, setHistTf] = useState<Timeframe>('6h')
  const [histChain, setHistChain] = useState('')
  const [history, setHistory] = useState<TrendingHistoryResponse | null>(null)
  const [historyLoading, setHistoryLoading] = useState(true)
  const [historyError, setHistoryError] = useState('')

  const [topVolume, setTopVolume] = useState<TopVolumeResponse | null>(null)
  const [topVolumeLoading, setTopVolumeLoading] = useState(true)
  const [topVolumeError, setTopVolumeError] = useState('')

  const [chainActivity, setChainActivity] = useState<ChainActivityResponse | null>(null)
  const [chainActivityLoading, setChainActivityLoading] = useState(true)
  const [chainActivityError, setChainActivityError] = useState('')

  const abortRef = useRef<AbortController | null>(null)
  const crossedAbortRef = useRef<AbortController | null>(null)
  const championsAbortRef = useRef<AbortController | null>(null)
  const riskWatchAbortRef = useRef<AbortController | null>(null)
  const trendingAbortRef = useRef<AbortController | null>(null)
  const historyAbortRef = useRef<AbortController | null>(null)
  const topVolumeAbortRef = useRef<AbortController | null>(null)
  const chainActivityAbortRef = useRef<AbortController | null>(null)

  const message = (error: unknown, fallback: string) =>
    error instanceof MemecoinApiError ? error.message : fallback

  const load = useCallback(async (nextChain: string, nextSort: MemecoinSort) => {
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    setFetching(true)
    try {
      const data = await fetchMemecoins({ chain: nextChain || undefined, sort: nextSort }, controller.signal)
      setResult(data)
      setErrorMessage('')
      setStatus('ready')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setErrorMessage(message(error, 'Unable to load memecoin data.'))
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
      setCrossed(await fetchRecentlyCrossed(undefined, controller.signal))
      setCrossedError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setCrossedError(message(error, 'Unable to load recent crossings.'))
    } finally {
      if (crossedAbortRef.current === controller) setCrossedLoading(false)
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
      setChampionsError(message(error, 'Unable to load monthly champions.'))
    } finally {
      if (championsAbortRef.current === controller) setChampionsLoading(false)
    }
  }, [])

  const loadRiskWatch = useCallback(async (nextChain: string) => {
    riskWatchAbortRef.current?.abort()
    const controller = new AbortController()
    riskWatchAbortRef.current = controller
    setRiskWatchLoading(true)
    try {
      setRiskWatch(await fetchRiskWatch(nextChain || undefined, controller.signal))
      setRiskWatchError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setRiskWatchError(message(error, 'Unable to load risk watch.'))
    } finally {
      if (riskWatchAbortRef.current === controller) setRiskWatchLoading(false)
    }
  }, [])

  const loadTrending = useCallback(async (tf: Timeframe, nextChain: string) => {
    trendingAbortRef.current?.abort()
    const controller = new AbortController()
    trendingAbortRef.current = controller
    setTrendingLoading(true)
    try {
      setTrending(await fetchTrending(tf, nextChain || undefined, controller.signal))
      setTrendingError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setTrendingError(message(error, 'Unable to load trending.'))
    } finally {
      if (trendingAbortRef.current === controller) setTrendingLoading(false)
    }
  }, [])

  const loadHistory = useCallback(async (tf: Timeframe, nextChain: string) => {
    historyAbortRef.current?.abort()
    const controller = new AbortController()
    historyAbortRef.current = controller
    setHistoryLoading(true)
    try {
      setHistory(await fetchTrendingHistory(tf, undefined, nextChain || undefined, controller.signal))
      setHistoryError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setHistoryError(message(error, "Unable to load yesterday's trending."))
    } finally {
      if (historyAbortRef.current === controller) setHistoryLoading(false)
    }
  }, [])

  const loadTopVolume = useCallback(async (nextChain: string) => {
    topVolumeAbortRef.current?.abort()
    const controller = new AbortController()
    topVolumeAbortRef.current = controller
    setTopVolumeLoading(true)
    try {
      setTopVolume(await fetchTopVolume(nextChain || undefined, controller.signal))
      setTopVolumeError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setTopVolumeError(message(error, 'Unable to load top volume.'))
    } finally {
      if (topVolumeAbortRef.current === controller) setTopVolumeLoading(false)
    }
  }, [])

  const loadChainActivity = useCallback(async () => {
    chainActivityAbortRef.current?.abort()
    const controller = new AbortController()
    chainActivityAbortRef.current = controller
    setChainActivityLoading(true)
    try {
      setChainActivity(await fetchChainActivity(controller.signal))
      setChainActivityError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setChainActivityError(message(error, 'Unable to load chain activity.'))
    } finally {
      if (chainActivityAbortRef.current === controller) setChainActivityLoading(false)
    }
  }, [])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void load(chain, sort)
  }, [chain, sort, load])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadCrossed()
  }, [loadCrossed])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadChampions()
  }, [loadChampions])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadRiskWatch(chain)
  }, [chain, loadRiskWatch])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadTrending(trendTf, chain)
  }, [trendTf, chain, loadTrending])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadHistory(histTf, histChain)
  }, [histTf, histChain, loadHistory])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadTopVolume(chain)
  }, [chain, loadTopVolume])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void loadChainActivity()
  }, [loadChainActivity])

  // Gentle auto-refresh — the main feeds every minute, trending every ~5 min.
  useEffect(() => {
    const timer = window.setInterval(() => {
      void load(chain, sort)
      void loadCrossed()
      void loadRiskWatch(chain)
      void loadTopVolume(chain)
      void loadChainActivity()
    }, AUTO_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [chain, sort, load, loadCrossed, loadRiskWatch, loadTopVolume, loadChainActivity])

  useEffect(() => {
    const timer = window.setInterval(() => {
      void loadTrending(trendTf, chain)
    }, TRENDING_REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [trendTf, chain, loadTrending])

  useEffect(
    () => () => {
      abortRef.current?.abort()
      crossedAbortRef.current?.abort()
      championsAbortRef.current?.abort()
      riskWatchAbortRef.current?.abort()
      trendingAbortRef.current?.abort()
      historyAbortRef.current?.abort()
      topVolumeAbortRef.current?.abort()
      chainActivityAbortRef.current?.abort()
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
    void loadRiskWatch(chain)
    void loadTrending(trendTf, chain)
    void loadTopVolume(chain)
    void loadChainActivity()
  }

  return (
    <main className="app">
      <header className="app-header">
        <div>
          <h1>Memecoin Detector</h1>
          <p className="subtitle">Trending first — then filtered for market quality &amp; risk</p>
        </div>
        <div className="controls">
          <ChainFilter value={chain} onChange={setChain} disabled={fetching} />
          <button type="button" onClick={refreshAll} disabled={fetching}>
            {fetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>

      <TrendingNow
        result={trending}
        timeframe={trendTf}
        chain={chain}
        loading={trendingLoading}
        error={trendingError}
        onTimeframe={setTrendTf}
        onChain={setChain}
        onRetry={() => void loadTrending(trendTf, chain)}
      />

      <RecentlyCrossedSection
        rows={crossed?.data ?? []}
        hours={recentHours}
        loading={crossedLoading}
        error={crossedError}
        onRetry={() => void loadCrossed()}
      />

      <section className="qualified-list">
        <div className="section-heading">
          <h2>🟢 Main Memecoin List</h2>
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

        <p className="muted">
          Main-list tokens are market-cap qualified <em>and</em> pass a conservative risk screen
          (mature enough, lower/medium risk, enough security data, no hard safety failure). A token
          can be trending and still not be here. This is a risk filter, not a guarantee of safety.
        </p>
      </section>

      <RiskWatchSection
        rows={riskWatch?.data ?? []}
        loading={riskWatchLoading}
        error={riskWatchError}
        onRetry={() => void loadRiskWatch(chain)}
      />

      <ChainActivity
        result={chainActivity}
        loading={chainActivityLoading}
        error={chainActivityError}
        onRetry={() => void loadChainActivity()}
      />

      <TopVolume
        result={topVolume}
        loading={topVolumeLoading}
        error={topVolumeError}
        onRetry={() => void loadTopVolume(chain)}
      />

      <MonthlyChampions
        months={champions?.data ?? []}
        year={champions?.meta.year ?? new Date().getUTCFullYear()}
        loading={championsLoading}
        error={championsError}
        onRetry={() => void loadChampions()}
      />

      <TrendingHistory
        result={history}
        timeframe={histTf}
        chain={histChain}
        loading={historyLoading}
        error={historyError}
        onTimeframe={setHistTf}
        onChain={setHistChain}
        onRetry={() => void loadHistory(histTf, histChain)}
      />

      <footer className="provenance">
        <p>
          Data source: <strong>DexScreener</strong> (documented APIs only)
          {' · '}Last observed: {latestObservation(rows) ?? '—'}
          {meta && <>{' · '}Retrieved: {formatDateTime(meta.retrieved_at)}</>}
        </p>
        <p className="muted">
          Trending is ranked by our transparent internal <code>tracked_trend_score</code> — not
          DexScreener&rsquo;s proprietary trending score, which is only on an undocumented feed.
          Volume figures are provider-reported, not certified organic.
        </p>
        <p className="muted">
          The dashboard reads persisted data from this app&rsquo;s API only
          (<code>{API_BASE_URL}/api/memecoins</code>, <code>/trending</code>,{' '}
          <code>/trending/history</code>, <code>/top-volume</code>, <code>/chain-activity</code>,{' '}
          <code>/recently-crossed</code>, <code>/risk-watch</code>, <code>/monthly-champions</code>).
          It never calls DexScreener, GoPlus or any provider directly, and never opens a WebSocket.
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
