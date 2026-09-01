import type { ChainActivityResponse } from '../types/memecoin'
import { formatPercentCompact, formatUsd } from '../lib/format'

interface ChainActivityProps {
  result: ChainActivityResponse | null
  loading: boolean
  error: string
  onRetry: () => void
}

/**
 * "📊 Chain Market Activity" — one card per chain bucket, from the materialised
 * `daily_chain_activity` table.
 *
 * "Reported Volume" — deduplicated token-level representative-pair volume per
 * bucket. It is NOT claimed to be organic / real human volume.
 */
export function ChainActivity({ result, loading, error, onRetry }: ChainActivityProps) {
  const cards = result?.data ?? []

  return (
    <section className="chain-activity">
      <div className="section-heading">
        <h2>📊 Chain Market Activity</h2>
        <span className="muted">reported 24h volume</span>
      </div>

      <p className="muted detail-note">
        Reported volume — one representative-pair figure per tracked token, summed per chain bucket
        (never double-counted across pools). Not certified as organic volume.
      </p>

      {loading && cards.length === 0 && <p className="state">Loading…</p>}

      {error && (
        <div className="state state-error">
          <p>{error}</p>
          <button type="button" onClick={onRetry}>
            Try again
          </button>
        </div>
      )}

      {cards.length > 0 && (
        <div className="chain-activity-grid">
          {cards.map((card) => (
            <article key={card.chain_bucket} className={`chain-card chain-card-${card.chain_bucket}`}>
              <header className="chain-card-head">
                <h3>{card.label}</h3>
                {card.volume_change_pct !== null && (
                  <span
                    className={`chain-delta ${card.volume_change_pct >= 0 ? 'chain-delta-up' : 'chain-delta-down'}`}
                    title="Reported 24h volume vs the previous day"
                  >
                    {formatPercentCompact(card.volume_change_pct)}
                  </span>
                )}
              </header>
              <dl className="chain-card-stats">
                <div>
                  <dt>24H Reported Volume</dt>
                  <dd className="strong">{formatUsd(card.total_volume_usd)}</dd>
                </div>
                <div>
                  <dt>Liquidity</dt>
                  <dd>{formatUsd(card.total_liquidity_usd)}</dd>
                </div>
                <div>
                  <dt>Active Tokens</dt>
                  <dd>{card.active_token_count}</dd>
                </div>
                <div>
                  <dt>Top Volume</dt>
                  <dd>
                    {card.top_token ? (
                      <>
                        {card.top_token.symbol ?? '—'}{' '}
                        <span className="muted">{formatUsd(card.top_token.reported_volume_usd)}</span>
                      </>
                    ) : (
                      '—'
                    )}
                  </dd>
                </div>
              </dl>
            </article>
          ))}
        </div>
      )}
    </section>
  )
}
