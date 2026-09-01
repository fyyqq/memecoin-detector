import { useNavigate } from 'react-router-dom'
import type { TopVolumeChain, TopVolumeResponse } from '../types/memecoin'
import { formatUsd } from '../lib/format'
import { RiskChip } from './RiskChip'

interface TopVolumeProps {
  result: TopVolumeResponse | null
  loading: boolean
  error: string
  onRetry: () => void
}

/**
 * "Top 5 Volume by Chain" — per chain bucket, the top tokens by REPORTED 24h
 * volume after the market-integrity gate (liquidity > 0, transactions > 0, sane
 * market cap, no extreme wash-trade shape).
 *
 * "Reported Volume" — the gate removes obvious anomalies; it does NOT certify
 * the remaining volume as organic / real human volume.
 */
export function TopVolume({ result, loading, error, onRetry }: TopVolumeProps) {
  const navigate = useNavigate()
  const chains = (result?.data ?? []).filter((c) => c.tokens.length > 0)

  const open = (row: TopVolumeChain['tokens'][number]) =>
    navigate(
      `/memecoin/${encodeURIComponent(row.chain_id)}/${encodeURIComponent(row.token_address)}`,
    )

  return (
    <section className="top-volume">
      <div className="section-heading">
        <h2>💧 Top Volume by Chain</h2>
        <span className="muted">reported volume</span>
      </div>

      {loading && chains.length === 0 && <p className="state">Loading…</p>}

      {error && (
        <div className="state state-error">
          <p>{error}</p>
          <button type="button" onClick={onRetry}>
            Try again
          </button>
        </div>
      )}

      {!loading && !error && chains.length === 0 && (
        <p className="muted">No tokens pass the market-integrity gate yet.</p>
      )}

      {chains.length > 0 && (
        <div className="top-volume-grid">
          {chains.map((chain) => (
            <article key={chain.chain_bucket} className="top-volume-card">
              <h3>{chain.label}</h3>
              <ol className="top-volume-list">
                {chain.tokens.map((token, i) => (
                  <li
                    key={token.token_address}
                    className="row-link"
                    role="link"
                    tabIndex={0}
                    aria-label={`View ${token.symbol ?? token.token_address} detail`}
                    onClick={() => open(token)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault()
                        open(token)
                      }
                    }}
                  >
                    <span className="top-volume-rank">{i + 1}</span>
                    <span className="top-volume-token">
                      <span className="symbol">{token.symbol ?? '—'}</span>
                      <span className="muted">
                        Liq {formatUsd(token.liquidity_usd)} · MC {formatUsd(token.market_cap)}
                      </span>
                    </span>
                    <span className="top-volume-vol strong">{formatUsd(token.reported_volume_usd)}</span>
                    <RiskChip level={token.risk_level} className="chip-compact" />
                  </li>
                ))}
              </ol>
            </article>
          ))}
        </div>
      )}
    </section>
  )
}
