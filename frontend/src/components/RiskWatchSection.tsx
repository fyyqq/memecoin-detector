import { useNavigate } from 'react-router-dom'
import type { RiskWatchRow } from '../types/memecoin'
import { formatAgeDays, formatUsd } from '../lib/format'
import { RiskChip } from './RiskChip'

interface RiskWatchSectionProps {
  rows: RiskWatchRow[]
  loading: boolean
  error: string
  onRetry: () => void
}

/**
 * "⚠️ Risk Watch" (Step 24) — tokens that ARE market-cap qualified but FAIL the
 * main-list risk screen (HIGH / CRITICAL / RISK UNKNOWN, too young, or a hard
 * safety filter). Shown for transparency — never hidden.
 *
 * This is a RISK FILTER, not a "safe to invest" signal.
 */
export function RiskWatchSection({ rows, loading, error, onRetry }: RiskWatchSectionProps) {
  const navigate = useNavigate()

  const open = (row: RiskWatchRow) =>
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )

  return (
    <section className="risk-watch">
      <div className="section-heading">
        <h2>⚠️ Risk Watch</h2>
        <span className="muted">qualified by market cap · failed a risk check</span>
      </div>

      <p className="qualification">
        These tokens meet the $5M–$200M market-cap qualification but failed one or more risk
        checks, or are too new / under-screened. Shown for transparency — this is not a
        &ldquo;safe to invest&rdquo; signal.
      </p>

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
        <p className="muted">No qualified token currently fails the risk screen.</p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table className="memecoin-table risk-watch-table">
            <thead>
              <tr>
                <th>Token</th>
                <th>Chain</th>
                <th className="num">Age</th>
                <th className="num">Current MC</th>
                <th className="num">Peak MC</th>
                <th>Risk</th>
                <th>Why flagged</th>
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
                    <span className="symbol">{row.symbol ?? '—'}</span>
                    <span className="name">{row.name ?? row.token_address}</span>
                  </td>
                  <td>{row.chain_id}</td>
                  <td className="num">{formatAgeDays(row.age_days)}</td>
                  <td className="num">{formatUsd(row.current_mc)}</td>
                  <td className="num strong">{formatUsd(row.peak_mc)}</td>
                  <td>
                    <RiskChip level={row.risk_level} score={row.risk_score} />
                  </td>
                  <td>
                    <ul className="risk-watch-reasons">
                      {reasonPhrases(row).map((phrase, index) => (
                        <li key={index}>{phrase}</li>
                      ))}
                    </ul>
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

/** Only phrases that were actually measured — never inferred from missing data. */
function reasonPhrases(row: RiskWatchRow): string[] {
  const fromSignals = row.failed_signals
    .map((s) => s.explanation)
    .filter((v): v is string => Boolean(v))
  const combined = [...row.reasons, ...fromSignals]
  return combined.length > 0 ? combined.slice(0, 5) : ['Risk unknown — insufficient security data.']
}
