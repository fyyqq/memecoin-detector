import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { API_BASE_URL } from '../api/memecoins'
import { fetchMemecoinDetail, MemecoinNotFoundError } from '../api/memecoinDetail'
import { CopyAddress } from '../components/CopyAddress'
import { MarketCapSparkline } from '../components/MarketCapSparkline'
import {
  formatAgeDays,
  formatDateTime,
  formatInteger,
  formatPercent,
  formatPrice,
  formatUsd,
} from '../lib/format'
import type { MemecoinDetail, MemecoinDetailResponse } from '../types/memecoinDetail'

type Status = 'loading' | 'ready' | 'error' | 'not-found'

export function MemecoinDetailPage() {
  const params = useParams<{ chainId: string; tokenAddress: string }>()
  const chainId = params.chainId ?? ''
  const tokenAddress = params.tokenAddress ?? ''

  const [status, setStatus] = useState<Status>('loading')
  const [response, setResponse] = useState<MemecoinDetailResponse | null>(null)
  const [errorMessage, setErrorMessage] = useState('')
  const abortRef = useRef<AbortController | null>(null)

  const load = useCallback(async () => {
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    setStatus('loading')

    try {
      const data = await fetchMemecoinDetail(chainId, tokenAddress, controller.signal)
      setResponse(data)
      setStatus('ready')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      if (error instanceof MemecoinNotFoundError) {
        setStatus('not-found')
        return
      }
      setErrorMessage(error instanceof Error ? error.message : 'Unable to load this memecoin.')
      setStatus('error')
    }
  }, [chainId, tokenAddress])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect
    void load()
  }, [load])

  useEffect(() => () => abortRef.current?.abort(), [])

  return (
    <main className="app">
      <p className="back-link">
        <Link to="/">← Back to dashboard</Link>
      </p>

      {status === 'loading' && <p className="state">Loading…</p>}

      {status === 'not-found' && (
        <div className="state">
          <p>Memecoin not found.</p>
          <p className="muted">
            No token with chain <code>{chainId}</code> and this contract address exists in our
            database.
          </p>
        </div>
      )}

      {status === 'error' && (
        <div className="state state-error">
          <p>{errorMessage || 'Unable to load this memecoin.'}</p>
          <button type="button" onClick={() => void load()}>
            Try again
          </button>
        </div>
      )}

      {status === 'ready' && response && (
        <DetailView detail={response.data} retrievedAt={response.meta.retrieved_at} />
      )}
    </main>
  )
}

interface DetailViewProps {
  detail: MemecoinDetail
  retrievedAt: string
}

function DetailView({ detail, retrievedAt }: DetailViewProps) {
  const chronological = [...detail.snapshots].reverse()

  return (
    <article className="detail">
      <header className="detail-header">
        <h1>{detail.name ?? 'Unknown token'}</h1>
        <p className="detail-symbol">
          {(detail.symbol ?? '—') + ' · ' + detail.chain_id}
        </p>
        <div className="detail-ca">
          <span className="detail-ca-label">CA</span>
          <code className="detail-ca-value" title={detail.token_address}>
            {detail.token_address}
          </code>
          <CopyAddress address={detail.token_address} />
        </div>
      </header>

      <Section title="Market overview">
        <div className="detail-grid">
          <Field label="Current Market Cap">{show(detail.current_market_cap, formatUsd)}</Field>
          <Field label="Observed Peak Market Cap">
            {show(detail.observed_peak_market_cap, formatUsd)}
          </Field>
          <Field label="Observed Peak at">
            {show(detail.observed_peak_market_cap_at, formatDateTime)}
          </Field>
          <Field label="Age">{show(detail.age_days, formatAgeDays)}</Field>
          <Field label="24h Price Change">{show(detail.price_change_h24, formatPercent)}</Field>
          <Field label="24h Volume">{show(detail.volume_h24, formatUsd)}</Field>
          <Field label="Liquidity">{show(detail.liquidity_usd, formatUsd)}</Field>
          <Field label="Price">{show(detail.price_usd, formatPrice)}</Field>
        </div>
        <p className="muted detail-note">
          &ldquo;Observed Peak Market Cap&rdquo; is the highest market cap this detector has
          captured since it started observing the token — not an all-time high or lifetime high.
        </p>
      </Section>

      <Section title="Market activity">
        <div className="detail-grid">
          <Field label="Primary DEX">{show(detail.primary_dex_id, (v) => v)}</Field>
          <Field label="Primary Pair">{show(detail.primary_pair_address, (v) => v)}</Field>
          <Field label="24h Volume">{show(detail.volume_h24, formatUsd)}</Field>
          <Field label="24h Transactions">{show(detail.txns_h24, formatInteger)}</Field>
          <Field label="Buys (24h)">{show(detail.buys_h24, formatInteger)}</Field>
          <Field label="Sells (24h)">{show(detail.sells_h24, formatInteger)}</Field>
        </div>
      </Section>

      <Section title="Token identity">
        <div className="detail-grid">
          <Field label="Chain">{detail.chain_id}</Field>
          <Field label="Token Name">{detail.name ?? 'Unavailable'}</Field>
          <Field label="Symbol">{detail.symbol ?? 'Unavailable'}</Field>
          <Field label="Pair Count">{show(detail.pair_count, formatInteger)}</Field>
          <Field label="Earliest Pair Created At">
            {show(detail.earliest_pair_created_at, formatDateTime)}
          </Field>
          <Field label="First Observed At">
            {show(detail.first_observed_at, formatDateTime)}
          </Field>
          <Field label="Last Observed At">{show(detail.last_observed_at, formatDateTime)}</Field>
          <Field label="Data Source">{detail.data_source}</Field>
        </div>
        <div className="detail-ca">
          <span className="detail-ca-label">Contract Address</span>
          <code className="detail-ca-value" title={detail.token_address}>
            {detail.token_address}
          </code>
          <CopyAddress address={detail.token_address} />
        </div>
      </Section>

      <Section title="Observation history">
        {chronological.length > 0 ? (
          <>
            <MarketCapSparkline values={chronological.map((row) => row.market_cap)} />
            <div className="table-wrap">
              <table className="snapshot-table">
                <thead>
                  <tr>
                    <th>Observed At</th>
                    <th>Market Cap</th>
                    <th>Price</th>
                    <th>Volume (24h)</th>
                    <th>Liquidity</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.snapshots.map((row, index) => (
                    <tr key={`${row.observed_at ?? 'row'}-${index}`}>
                      <td>{formatDateTime(row.observed_at)}</td>
                      <td>{formatUsd(row.market_cap)}</td>
                      <td>{formatPrice(row.price_usd)}</td>
                      <td>{formatUsd(row.volume_h24)}</td>
                      <td>{formatUsd(row.liquidity_usd)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="muted detail-note">
              Most recent stored observations, newest first. Charts and full history come later.
            </p>
          </>
        ) : (
          <p className="muted">No observations stored for this token yet.</p>
        )}
      </Section>

      <Section title="Why did this coin pump?">
        <div className="placeholder-card">
          <p className="placeholder-lead">Pump intelligence is not available yet.</p>
          <p className="muted">
            This section will show evidence-backed catalysts, related-token movement, and
            supporting evidence when that intelligence layer is added.
          </p>
        </div>
      </Section>

      <Section title="Why was this coin created?">
        <div className="placeholder-card">
          <p className="placeholder-lead">Token origin analysis is not available yet.</p>
          <p className="muted">
            We do not infer purpose, creator intent, or narrative without stored evidence.
            Token-origin analysis will be added later.
          </p>
        </div>
      </Section>

      <Section title="Data provenance">
        <p>
          Data source: <strong>DexScreener</strong>
          {' · '}Retrieved: {formatDateTime(retrievedAt)}
        </p>
        <p className="muted">
          This page reads persisted observations from this app&rsquo;s API
          (<code>{`${API_BASE_URL}/api/memecoins/${detail.chain_id}/${detail.token_address}`}</code>).
          It never calls DexScreener directly.
        </p>
        <p className="muted">
          Observed peak reflects the highest market cap captured by this detector, not guaranteed
          lifetime history.
        </p>
      </Section>
    </article>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="detail-section">
      <h2>{title}</h2>
      {children}
    </section>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="data-row">
      <span className="data-label">{label}</span>
      <span className="data-value">{children}</span>
    </div>
  )
}

/** "Unavailable" for missing data — never a fabricated zero. */
function show<T>(value: T | null | undefined, render: (value: T) => string): string {
  return value === null || value === undefined ? 'Unavailable' : render(value)
}
