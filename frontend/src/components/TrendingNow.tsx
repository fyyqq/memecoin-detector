import { useNavigate } from 'react-router-dom'
import type { Timeframe, TrendingResponse, TrendingRow } from '../types/memecoin'
import { TIMEFRAMES } from '../types/memecoin'
import { formatAgeDays, formatRelativeTime, formatUsd } from '../lib/format'
import { formatTrendScore, TIMEFRAME_LABEL, TREND_CHAIN_OPTIONS, trendTone } from '../lib/trend'
import { RiskChip } from './RiskChip'

interface TrendingNowProps {
  result: TrendingResponse | null
  timeframe: Timeframe
  chain: string
  loading: boolean
  error: string
  onTimeframe: (tf: Timeframe) => void
  onChain: (chain: string) => void
  onRetry: () => void
}

/**
 * "🔥 Top Trending Memecoins" — the TOP N (default 10, max 20) currently-trending,
 * NEWLY-LAUNCHED memecoins that pass our approved filters: memecoin, age ≤ 30d,
 * CURRENT market cap $5M–$200M, real volume + liquidity.
 *
 * This is NOT "all trending tokens". "Tracked Trending" is our transparent
 * internal ranking — NOT DexScreener's proprietary trending score. Trending is
 * attention, not safety: each row shows its risk level, and a stale scan is
 * flagged "RISK CHECK STALE".
 */
export function TrendingNow({
  result,
  timeframe,
  chain,
  loading,
  error,
  onTimeframe,
  onChain,
  onRetry,
}: TrendingNowProps) {
  const navigate = useNavigate()
  const rows = result?.data ?? []
  const filters = result?.meta.filters
  const capturedAt = result?.meta.captured_at ?? null

  const open = (row: TrendingRow) => {
    if (!row.is_tracked) return
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )
  }

  return (
    <section className="trending-now">
      <div className="section-heading">
        <h2>🔥 Top Trending Memecoins</h2>
        <span className="muted">
          {capturedAt ? `Updated ${formatRelativeTime(capturedAt)}` : `Updated every ~${result?.meta.refresh_minutes ?? 5} minutes`}
        </span>
      </div>

      <p className="muted detail-note">
        The top {result?.meta.top_n ?? 10} currently-trending, newly-launched memecoins —
        {filters
          ? ` memecoin · age ≤ ${filters.max_age_days}d · current MC ${formatUsd(filters.min_current_market_cap)}–${formatUsd(filters.max_current_market_cap)} · volume & liquidity > 0`
          : ' filtered for market quality'}
        . Ranked by our transparent internal <strong>tracked trend score</strong> (momentum + volume
        + transactions + liquidity + persistence) — not DexScreener&rsquo;s proprietary score, and
        not a safety signal.
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
        <p className="muted">
          No newly-launched memecoin is trending in this view right now (memecoin + age ≤ 30d +
          current MC $5M–$200M + activity).
        </p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table className="memecoin-table trending-table">
            <thead>
              <tr>
                <th className="num">#</th>
                <th>Token</th>
                <th>Chain</th>
                <th className="num">Age</th>
                <th className="num">MC</th>
                <th className="num">Volume ({TIMEFRAME_LABEL[timeframe]})</th>
                <th className="num">Liquidity</th>
                <th className="num">Trend</th>
                <th>Risk</th>
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
                  <td className="num strong">{row.rank}</td>
                  <td>
                    <span className="symbol">
                      <span aria-hidden="true">🔥</span> {row.symbol ?? '—'}
                      {row.main_list_eligible && (
                        <span className="trend-tag trend-tag-main" title="Passes market qualification + the risk screen">
                          main list
                        </span>
                      )}
                    </span>
                    <span className="name">
                      {row.name ?? row.token_address}
                      {row.trending_meta_name && ` · ${row.trending_meta_name}`}
                    </span>
                  </td>
                  <td>{row.chain_id}</td>
                  <td className="num">{formatAgeDays(row.age_days)}</td>
                  <td className="num">{formatUsd(row.market_cap)}</td>
                  <td className="num">{formatUsd(row.volume_usd)}</td>
                  <td className="num">{formatUsd(row.liquidity_usd)}</td>
                  <td className="num">
                    <span className={`trend-score trend-score-${trendTone(row.tracked_trend_score)}`}>
                      {formatTrendScore(row.tracked_trend_score)}
                    </span>
                  </td>
                  <td>
                    <RiskChip level={row.risk_level} score={row.risk_score} />
                    {row.risk_check_stale && (
                      <span
                        className="risk-stale"
                        title={
                          row.risk_checked_at
                            ? `Last risk scan ${formatRelativeTime(row.risk_checked_at)} — treat as unverified.`
                            : 'Not risk-screened yet — treat as unverified.'
                        }
                      >
                        RISK CHECK STALE
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
