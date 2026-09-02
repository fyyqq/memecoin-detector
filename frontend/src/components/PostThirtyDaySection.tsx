import { useNavigate } from 'react-router-dom'
import type {
  PostThirtyDayResponse,
  PostThirtyDayRow,
  PostThirtyDaySort,
  SortDirection,
} from '../types/memecoin'
import { ChainFilter } from './ChainFilter'
import { RiskChip } from './RiskChip'
import { formatAgeDays, formatUsd } from '../lib/format'

interface PostThirtyDaySectionProps {
  response: PostThirtyDayResponse | null
  chain: string
  sort: PostThirtyDaySort
  direction: SortDirection
  loading: boolean
  error: string
  onChainChange: (chain: string) => void
  onSortChange: (sort: PostThirtyDaySort) => void
  onDirectionToggle: () => void
  onRetry: () => void
}

const SORT_LABELS: Array<{ value: PostThirtyDaySort; label: string }> = [
  { value: 'peak_market_cap', label: 'Peak Market Cap' },
  { value: 'market_cap', label: 'Current Market Cap' },
  { value: 'volume', label: '24H Volume' },
  { value: 'liquidity', label: 'Liquidity' },
  { value: 'holders', label: 'Holders' },
  { value: 'age', label: 'Age' },
]

/**
 * "📈 Post-30-Day Memecoins" — previously approved memecoins now older than 30
 * days. A continuation / tracking table (never "trending" / "top performers" /
 * "safe"). Historical approval is preserved even after a token dumps or its risk
 * level rises; the current risk chip is shown for transparency.
 */
export function PostThirtyDaySection({
  response,
  chain,
  sort,
  direction,
  loading,
  error,
  onChainChange,
  onSortChange,
  onDirectionToggle,
  onRetry,
}: PostThirtyDaySectionProps) {
  const navigate = useNavigate()
  const rows = response?.data ?? []
  const thresholdDays = response?.meta.age_threshold_days ?? 30

  const open = (row: PostThirtyDayRow) =>
    navigate(`/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`)

  return (
    <section className="post-thirty-day">
      <div className="section-heading">
        <h2>📈 Post-30-Day Memecoins</h2>
        <span className="muted">Previously approved memecoins now older than {thresholdDays} days</span>
      </div>

      <div className="post-thirty-controls">
        <ChainFilter value={chain} onChange={onChainChange} disabled={loading} />
        <label className="sort-control">
          <span className="muted">Sort</span>
          <select
            value={sort}
            disabled={loading}
            onChange={(event) => onSortChange(event.target.value as PostThirtyDaySort)}
          >
            {SORT_LABELS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <button
          type="button"
          className="sort-direction"
          disabled={loading}
          onClick={onDirectionToggle}
          aria-label={`Sort ${direction === 'desc' ? 'descending' : 'ascending'}`}
          title={`Sort ${direction === 'desc' ? 'descending' : 'ascending'}`}
        >
          {direction === 'desc' ? '↓ Desc' : '↑ Asc'}
        </button>
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
          No previously approved memecoins have moved beyond {thresholdDays} days yet.
        </p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table className="memecoin-table post-thirty-table">
            <thead>
              <tr>
                <th>Token</th>
                <th>Chain</th>
                <th className="num">Age</th>
                <th className="num">Current MC</th>
                <th className="num">Peak MC</th>
                <th className="num">24H Vol</th>
                <th className="num">Liquidity</th>
                <th>Risk</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr
                  key={row.id}
                  className="row-link"
                  role="link"
                  tabIndex={0}
                  aria-label={`View ${row.symbol ?? row.token_address} detail`}
                  onClick={() => open(row)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      open(row)
                    }
                  }}
                >
                  <td>
                    <span className="symbol">
                      <span aria-hidden="true">📈</span> {row.symbol ?? '—'}
                      <span
                        className={`crossing-status crossing-status-${row.status.toLowerCase()}`}
                        title={
                          row.status === 'ACTIVE'
                            ? 'Current market cap is at or above $5M'
                            : 'Current market cap has fallen below $5M — still tracked (previously approved)'
                        }
                      >
                        {row.status}
                      </span>
                    </span>
                    <span className="name">
                      {row.name ?? row.token_address}
                      {row.days_to_cross != null && (
                        <span className="muted"> · crossed $5M {row.days_to_cross}d after launch</span>
                      )}
                    </span>
                  </td>
                  <td>{row.chain_id}</td>
                  <td className="num">{formatAgeDays(row.age_days)}</td>
                  <td className="num">{formatUsd(row.current_market_cap)}</td>
                  <td className="num strong">{formatUsd(row.peak_market_cap)}</td>
                  <td className="num">{formatUsd(row.volume_h24)}</td>
                  <td className="num">{formatUsd(row.liquidity_usd)}</td>
                  <td>
                    <RiskChip level={row.risk_level} score={row.risk_score} />
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
