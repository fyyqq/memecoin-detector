import type { Memecoin } from '../types/memecoin'
import { formatAgeDays, formatDateTime, formatRelativeTime, formatUsd } from '../lib/format'

interface MemecoinTableProps {
  rows: Memecoin[]
}

export function MemecoinTable({ rows }: MemecoinTableProps) {
  return (
    <div className="table-wrap">
      <table className="memecoin-table">
        <thead>
          <tr>
            <th>Token</th>
            <th>Chain</th>
            <th className="num">Age</th>
            <th className="num">Current MC</th>
            <th className="num">Observed Peak MC</th>
            <th>Peak observed</th>
            <th className="num">24h Volume</th>
            <th className="num">Liquidity</th>
            <th>DEX</th>
            <th>Last observed</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              <td>
                <span className="symbol">{row.symbol ?? '—'}</span>
                <span className="name">{row.name ?? row.token_address}</span>
              </td>
              <td>{row.chain_id}</td>
              <td className="num">{formatAgeDays(row.age_days)}</td>
              <td className="num">{formatUsd(row.current_market_cap)}</td>
              <td className="num strong">{formatUsd(row.observed_peak_market_cap)}</td>
              <td title={row.observed_peak_market_cap_at ?? undefined}>
                {formatDateTime(row.observed_peak_market_cap_at)}
              </td>
              <td className="num">{formatUsd(row.volume_h24)}</td>
              <td className="num">{formatUsd(row.liquidity_usd)}</td>
              <td>{row.primary_dex_id ?? '—'}</td>
              <td title={row.last_observed_at ?? undefined}>
                {formatRelativeTime(row.last_observed_at)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
