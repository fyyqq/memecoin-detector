import { useNavigate } from 'react-router-dom'
import type { RecentlyCrossedRow } from '../types/memecoin'
import { formatRelativeTime, formatUsd } from '../lib/format'

interface RecentlyCrossedSectionProps {
  rows: RecentlyCrossedRow[]
  hours: number
  loading: boolean
  error: string
  onRetry: () => void
}

/**
 * "🔥 Recently Crossed $5M" — the compact card list of tokens whose
 * verified/observed $5M crossing landed inside the recent window. A token whose
 * current MC has since fallen below $5M still appears (COOLED); the floor is a
 * peak rule.
 */
export function RecentlyCrossedSection({
  rows,
  hours,
  loading,
  error,
  onRetry,
}: RecentlyCrossedSectionProps) {
  const navigate = useNavigate()

  const open = (row: RecentlyCrossedRow) =>
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )

  return (
    <section className="recently-crossed">
      <div className="section-heading">
        <h2>🔥 Recently Crossed $5M</h2>
        <span className="muted">last {hours}h</span>
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
        <p className="muted">No tracked memecoin has crossed $5M in the last {hours} hours.</p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table className="memecoin-table crossed-table">
            <thead>
              <tr>
                <th>Token</th>
                <th>Chain</th>
                <th>Crossed</th>
                <th className="num">Current MC</th>
                <th className="num">Peak MC</th>
                <th>Status</th>
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
                      <span aria-hidden="true">🔥</span> {row.symbol ?? '—'}
                      {row.crossing_type === 'HISTORICAL_VERIFIED' && (
                        <span className="crossing-tag" title="Historically verified crossing">
                          verified
                        </span>
                      )}
                    </span>
                    <span className="name">{row.name ?? row.token_address}</span>
                  </td>
                  <td>{row.chain_id}</td>
                  <td title={row.crossed_at}>{formatRelativeTime(row.crossed_at)}</td>
                  <td className="num">{formatUsd(row.current_market_cap)}</td>
                  <td className="num strong">
                    {formatUsd(row.qualification_peak ?? row.observed_peak_market_cap)}
                  </td>
                  <td>
                    <span className={`crossing-status crossing-status-${row.status.toLowerCase()}`}>
                      {row.status}
                    </span>
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
