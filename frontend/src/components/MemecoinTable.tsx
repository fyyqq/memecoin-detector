import { useNavigate } from 'react-router-dom'
import type { Memecoin } from '../types/memecoin'
import { formatAgeDays, formatUsd } from '../lib/format'
import { CopyAddress } from './CopyAddress'
import { QualificationBadge } from './QualificationBadge'
import { RiskChip } from './RiskChip'

interface MemecoinTableProps {
  rows: Memecoin[]
}

/**
 * The "🟢 Main Memecoin List" (Step 24) — market-cap qualified AND
 * lower/medium-risk. HIGH / CRITICAL / RISK UNKNOWN tokens are on Risk Watch.
 */
export function MemecoinTable({ rows }: MemecoinTableProps) {
  const navigate = useNavigate()

  const open = (row: Memecoin) =>
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )

  return (
    <div className="table-wrap">
      <table className="memecoin-table">
        <thead>
          <tr>
            <th>Token</th>
            <th>Chain</th>
            <th className="num">Age</th>
            <th className="num">Current MC</th>
            <th className="num">Peak MC</th>
            <th>Risk</th>
            <th className="num">24h Volume</th>
            <th className="num">Liquidity</th>
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
                  {row.symbol ?? '—'}
                  <QualificationBadge status={row.qualification_status} className="badge-inline" />
                  <CopyAddress address={row.token_address} className="copy-inline" />
                </span>
                <span className="name">{row.name ?? row.token_address}</span>
              </td>
              <td>{row.chain_id}</td>
              <td className="num">{formatAgeDays(row.age_days)}</td>
              <td className="num">{formatUsd(row.current_market_cap)}</td>
              <td className="num strong">{formatUsd(row.observed_peak_market_cap)}</td>
              <td>
                <RiskChip level={row.risk_level} score={row.risk_score} />
                {row.risk_summary.length > 0 && (
                  <span className="risk-summary" title={row.risk_summary.join(' · ')}>
                    {row.risk_summary[0]}
                  </span>
                )}
              </td>
              <td className="num">{formatUsd(row.volume_h24)}</td>
              <td className="num">{formatUsd(row.liquidity_usd)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
