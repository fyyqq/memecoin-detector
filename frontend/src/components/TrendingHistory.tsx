import { useNavigate } from 'react-router-dom'
import type { Timeframe, TrendingHistoryResponse, TrendingHistoryRow } from '../types/memecoin'
import { TIMEFRAMES } from '../types/memecoin'
import { formatUsd } from '../lib/format'
import { formatTrendScore, TIMEFRAME_LABEL, TREND_CHAIN_OPTIONS } from '../lib/trend'

interface TrendingHistoryProps {
  result: TrendingHistoryResponse | null
  timeframe: Timeframe
  chain: string
  loading: boolean
  error: string
  onTimeframe: (tf: Timeframe) => void
  onChain: (chain: string) => void
  onRetry: () => void
}

/**
 * "Trending Yesterday" — historical observations from the daily archive
 * (`daily_trending_rankings`). NOT recomputed from current state. A token that
 * trended yesterday stays here even if it stopped trending today.
 */
export function TrendingHistory({
  result,
  timeframe,
  chain,
  loading,
  error,
  onTimeframe,
  onChain,
  onRetry,
}: TrendingHistoryProps) {
  const navigate = useNavigate()
  const rows = result?.data ?? []
  const heading = result?.meta.is_yesterday ? "Yesterday's Trending" : `Trending — ${result?.meta.date ?? ''}`

  const open = (row: TrendingHistoryRow) => {
    if (!row.is_tracked) return
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )
  }

  return (
    <section className="trending-history">
      <div className="section-heading">
        <h2>📜 {heading}</h2>
        <span className="muted">historical archive</span>
      </div>

      <p className="muted detail-note">
        These are historical observations — the best rank / score each token reached on that day.
        Not recomputed from the current market.
      </p>

      <div className="trend-controls">
        <div className="trend-tabs" role="group" aria-label="Timeframe">
          {TIMEFRAMES.map((tf) => (
            <button
              key={tf}
              type="button"
              className={tf === timeframe ? 'is-active' : ''}
              onClick={() => onTimeframe(tf)}
            >
              {TIMEFRAME_LABEL[tf]}
            </button>
          ))}
        </div>
        <div className="trend-chain-filter" role="group" aria-label="Chain">
          {TREND_CHAIN_OPTIONS.map((option) => (
            <button
              key={option.value || 'all'}
              type="button"
              className={option.value === chain ? 'is-active' : ''}
              onClick={() => onChain(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      {loading && rows.length === 0 && <p className="state">Loading…</p>}

      {error && (
        <div className="state state-error">
          <p>{error}</p>
          <button type="button" onClick={onRetry}>
            Try again
          </button>
        </div>
      )}

      {!loading && !error && rows.length === 0 && (
        <p className="muted">No trending history recorded for this day yet.</p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table className="memecoin-table trending-table">
            <thead>
              <tr>
                <th className="num">Best #</th>
                <th>Token</th>
                <th>Chain</th>
                <th className="num">Peak MC</th>
                <th className="num">Peak Vol</th>
                <th className="num">Trend</th>
                <th className="num">Appearances</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr
                  key={`${row.chain_id}:${row.token_address}`}
                  className={row.is_tracked ? 'row-link' : undefined}
                  {...(row.is_tracked
                    ? {
                        role: 'link' as const,
                        tabIndex: 0,
                        'aria-label': `View ${row.symbol ?? row.token_address} detail`,
                        onClick: () => open(row),
                        onKeyDown: (event: React.KeyboardEvent) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            open(row)
                          }
                        },
                      }
                    : {})}
                >
                  <td className="num strong">{row.best_rank}</td>
                  <td>
                    <span className="symbol">
                      {row.symbol ?? '—'}
                    </span>
                    <span className="name">
                      {row.name ?? row.token_address}
                      {row.trending_meta_name && ` · ${row.trending_meta_name}`}
                    </span>
                  </td>
                  <td>{row.chain_id}</td>
                  <td className="num">{formatUsd(row.peak_market_cap)}</td>
                  <td className="num">{formatUsd(row.peak_volume)}</td>
                  <td className="num">
                    <span className="trend-score trend-score-mild">{formatTrendScore(row.best_score)}</span>
                  </td>
                  <td className="num">{row.appearances}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
