import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { API_BASE_URL } from '../api/memecoins'
import { fetchMemecoinDetail, MemecoinNotFoundError } from '../api/memecoinDetail'
import { CopyAddress } from '../components/CopyAddress'
import { MarketCapSparkline } from '../components/MarketCapSparkline'
import { TokenNarrativeSection } from '../components/TokenNarrativeSection'
import {
  formatAgeDays,
  formatDateTime,
  formatInteger,
  formatPercent,
  formatPercentCompact,
  formatPrice,
  formatUsd,
  truncateMiddle,
} from '../lib/format'
import { basisLabel, confidenceLabel, sourceLabel, statusPresentation } from '../lib/qualification'
import { RISK_SIGNAL_GROUP_LABELS, riskPresentation, signalStateIcon } from '../lib/risk'
import { RiskChip } from '../components/RiskChip'
import type {
  CitedEvidence,
  MemecoinDetail,
  MemecoinDetailResponse,
  PumpExplanation,
  PumpIntelligenceEvent,
  RiskAssessment,
  RiskSignal,
} from '../types/memecoinDetail'

type Status = 'loading' | 'ready' | 'error' | 'not-found'

/**
 * DexScreener embedded chart (Step 17).
 *
 * This is the ONE exception to "browser → our Laravel API only": the chart is a
 * third-party <iframe> served by DexScreener. Our JavaScript never calls
 * api.dexscreener.com. The URL is built ONLY from values our own API returned
 * (chain + representative pair) after they pass these format checks — never from
 * a raw browser-supplied URL.
 */
const DEXSCREENER_EMBED_ORIGIN = 'https://dexscreener.com'
const CHAIN_ID_RE = /^[a-z0-9][a-z0-9_-]{1,31}$/i
const PAIR_ADDRESS_RE = /^(0x[a-fA-F0-9]{40}|[1-9A-HJ-NP-Za-km-z]{32,64})$/

function buildDexScreenerEmbedUrl(chainId: string, pairAddress: string): string | null {
  if (!CHAIN_ID_RE.test(chainId) || !PAIR_ADDRESS_RE.test(pairAddress)) return null

  const prefersDark =
    typeof window !== 'undefined' &&
    (window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false)

  const params = new URLSearchParams({
    embed: '1',
    theme: prefersDark ? 'dark' : 'light',
    trades: '0',
    info: '0',
  })

  return `${DEXSCREENER_EMBED_ORIGIN}/${encodeURIComponent(chainId)}/${encodeURIComponent(
    pairAddress,
  )}?${params.toString()}`
}

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
        <Link to="/">← Back to 30-Day Leaders</Link>
      </p>

      {status === 'loading' && <p className="state">Loading…</p>}

      {status === 'not-found' && (
        <div className="state">
          <p>Memecoin not found.</p>
          <p className="muted">
            No token with chain <code>{chainId}</code> and this contract address exists in our
            database. <Link to="/">Back to 30-Day Leaders</Link>
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
  const { qualification: q, historical_estimate: estimate, observed, latest, pair, provenance } = detail
  const chronological = [...detail.snapshots].reverse()

  return (
    <article className="detail">
      {/* 1. HEADER */}
      <header className="detail-header">
        <h1>{detail.name ?? 'Unknown token'}</h1>
        <p className="detail-symbol">{(detail.symbol ?? '—') + ' · ' + detail.chain_id}</p>
        <div className="detail-ca">
          <span className="detail-ca-label">Contract Address</span>
          <code className="detail-ca-value" title={detail.token_address}>
            {truncateMiddle(detail.token_address)}
          </code>
          <CopyAddress address={detail.token_address} />
        </div>
      </header>

      {/* 2. LIVE MARKET CHART */}
      <Section title="Live market chart">
        <LiveMarketChart
          chainId={detail.chain_id}
          pairAddress={latest.primary_pair_address}
          dexId={latest.primary_dex_id}
        />
      </Section>

      {/* 3. MARKET OVERVIEW */}
      <Section title="Market overview">
        <div className="stat-grid">
          <Stat label="Current MC" value={show(latest.market_cap, formatUsd)} />
          <Stat
            label="Observed Peak MC"
            value={show(observed.peak_market_cap, formatUsd)}
            note="Highest MC captured by this detector"
          />
          <Stat
            label="Qualification Peak"
            value={show(q.peak_value, formatUsd)}
            note={
              q.qualified
                ? `Verified — ${basisLabel(q.basis)}`
                : q.status === 'HISTORICAL_ESTIMATE'
                  ? 'Not verified (FDV estimate only)'
                  : 'Not verified'
            }
          />
          <Stat label="Age" value={show(detail.age_days, formatAgeDays)} />
          <Stat label="24h Volume" value={show(latest.volume_h24, formatUsd)} />
          <Stat label="Liquidity" value={show(latest.liquidity_usd, formatUsd)} />
          {estimate && (
            <Stat
              label="Historical FDV estimate"
              value={show(estimate.estimated_fdv_usd, formatUsd)}
              note="Informational — not a market cap"
            />
          )}
        </div>
        <p className="muted detail-note">
          <strong>Observed Peak MC</strong> is the highest market cap this detector has captured
          since it started watching the token. <strong>Qualification Peak</strong> is a verified or
          observed market cap used to decide the token has ever reached $5M.
          {estimate && (
            <>
              {' '}
              The <strong>Historical FDV estimate</strong> is peak price × total supply — an estimate
              of fully-diluted value, <strong>not</strong> a verified market cap, and it does not put
              the token on the main list.
            </>
          )}
        </p>
      </Section>

      {/* 4. QUALIFICATION EVIDENCE */}
      <Section title="Why is this token on the list?">
        <QualificationEvidence detail={detail} />
      </Section>

      {/* 4b. QUALIFICATION TIMELINE */}
      <Section title="Qualification timeline">
        <QualificationTimeline detail={detail} />
      </Section>

      {/* 4d. RISK ASSESSMENT */}
      <Section title="Risk Assessment">
        <RiskAssessmentBlock risk={detail.risk_assessment} />
      </Section>

      {/* 5. PUMP EVENTS + EXPLANATIONS */}
      <Section title="Pump events">
        <PumpEvents events={detail.pump_intelligence.events} />
      </Section>

      {/* 6. MARKET ACTIVITY */}
      <Section title="Market activity">
        <div className="detail-grid">
          <Field label="Price">{show(latest.price_usd, formatPrice)}</Field>
          <Field label="Current MC">{show(latest.market_cap, formatUsd)}</Field>
          <Field label="FDV">{show(latest.fdv, formatUsd)}</Field>
          <Field label="Liquidity">{show(latest.liquidity_usd, formatUsd)}</Field>
          <Field label="24h Volume">{show(latest.volume_h24, formatUsd)}</Field>
          <Field label="24h Price Change">{show(latest.price_change_h24, formatPercent)}</Field>
          <Field label="24h Transactions">{show(latest.txns_h24, formatInteger)}</Field>
          <Field label="Buys (24h)">{show(latest.buys_h24, formatInteger)}</Field>
          <Field label="Sells (24h)">{show(latest.sells_h24, formatInteger)}</Field>
          <Field label="DEX">{show(latest.primary_dex_id, (v) => v)}</Field>
          <Field label="Primary Pair">{show(latest.primary_pair_address, (v) => v)}</Field>
        </div>
      </Section>

      {/* 7. OBSERVATION HISTORY */}
      <Section title="Observation history">
        {chronological.length > 0 ? (
          <>
            <MarketCapSparkline values={chronological.map((row) => row.market_cap)} />
            <div className="table-wrap">
              <table className="snapshot-table">
                <thead>
                  <tr>
                    <th>Observed At</th>
                    <th>Price</th>
                    <th>Market Cap</th>
                    <th>FDV</th>
                    <th>Volume</th>
                    <th>Liquidity</th>
                    <th>Transactions</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.snapshots.map((row, index) => (
                    <tr key={`${row.observed_at ?? 'row'}-${index}`}>
                      <td>{formatDateTime(row.observed_at)}</td>
                      <td>{formatPrice(row.price_usd)}</td>
                      <td>{formatUsd(row.market_cap)}</td>
                      <td>{formatUsd(row.fdv)}</td>
                      <td>{formatUsd(row.volume_h24)}</td>
                      <td>{formatUsd(row.liquidity_usd)}</td>
                      <td>{formatInteger(row.txns_h24)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="muted detail-note">
              The {detail.snapshots.length} most recent stored observations, newest first.
            </p>
          </>
        ) : (
          <p className="muted">No observations stored for this token yet.</p>
        )}
      </Section>

      {/* 8. TOKEN IDENTITY */}
      <Section title="Token identity">
        <div className="detail-grid">
          <Field label="Chain">{detail.chain_id}</Field>
          <Field label="Token Name">{detail.name ?? 'Unavailable'}</Field>
          <Field label="Symbol">{detail.symbol ?? 'Unavailable'}</Field>
          <Field label="Pair Count">{show(pair.pair_count, formatInteger)}</Field>
          <Field label="Earliest Pair Created At">
            {show(pair.earliest_pair_created_at, formatDateTime)}
          </Field>
          <Field label="First Observed At">
            {show(observed.first_observed_at, formatDateTime)}
          </Field>
          <Field label="Last Observed At">{show(observed.last_observed_at, formatDateTime)}</Field>
          <Field label="Data Source">{provenance.data_source}</Field>
        </div>
        <div className="detail-ca">
          <span className="detail-ca-label">Contract Address</span>
          <code className="detail-ca-value" title={detail.token_address}>
            {truncateMiddle(detail.token_address, 8, 6)}
          </code>
          <CopyAddress address={detail.token_address} />
        </div>
      </Section>

      {/* 9. TOKEN NARRATIVE INTELLIGENCE — origin + popularity */}
      <Section title="Token narrative intelligence">
        <TokenNarrativeSection narrative={detail.token_narrative} />
      </Section>

      {/* 10. DATA PROVENANCE */}
      <Section title="Data provenance">
        <div className="detail-grid">
          <Field label="Data source">DexScreener</Field>
          <Field label="Latest observation">
            {show(provenance.last_observed_at, formatDateTime)}
          </Field>
          <Field label="Historical qualification">
            {provenance.historical_qualification_source
              ? sourceLabel(provenance.historical_qualification_source)
              : 'Unavailable'}
          </Field>
          <Field label="Retrieved">{formatDateTime(retrievedAt)}</Field>
        </div>
        <p className="muted detail-note">
          Observed peak reflects the highest market cap captured by this detector.
        </p>
        {provenance.historical_estimate_note && (
          <p className="muted detail-note">{provenance.historical_estimate_note}</p>
        )}
        <p className="muted detail-note">
          This page reads persisted observations from this app&rsquo;s API
          (<code>{`${API_BASE_URL}/api/memecoins/${detail.chain_id}/${detail.token_address}`}</code>).
          Our JavaScript never calls DexScreener, CoinGecko or GeckoTerminal. The only third-party
          content on this page is the embedded DexScreener chart <em>iframe</em>, which loads
          directly from dexscreener.com and updates itself.
        </p>
      </Section>
    </article>
  )
}

function LiveMarketChart({
  chainId,
  pairAddress,
  dexId,
}: {
  chainId: string
  pairAddress: string | null
  dexId: string | null
}) {
  const src = pairAddress ? buildDexScreenerEmbedUrl(chainId, pairAddress) : null

  if (!src || !pairAddress) {
    return (
      <div className="placeholder-card">
        <p className="placeholder-lead">Live chart unavailable.</p>
        <p className="muted">
          {pairAddress
            ? 'The recorded pair reference is not in an expected on-chain address format, so the DexScreener chart cannot be embedded safely.'
            : 'We have not recorded a primary liquidity pair for this token yet, so the DexScreener chart cannot be embedded.'}
        </p>
      </div>
    )
  }

  return (
    <>
      <div className="chart-embed">
        <iframe
          src={src}
          title="DexScreener live chart"
          loading="lazy"
          referrerPolicy="no-referrer"
          allow="clipboard-write"
        />
      </div>
      <p className="muted detail-note">
        Chart: primary liquidity pair{dexId ? ` on ${dexId}` : ''} (
        <code>{truncateMiddle(pairAddress, 8, 6)}</code>). The primary pair is the one with the
        highest liquidity among the token&rsquo;s pairs, selected by our backend.
      </p>
      <p className="muted detail-note">
        Data source: DexScreener. This is an embedded third-party visualization served directly by
        DexScreener — it is independent of our stored API data and updates on its own.
      </p>
    </>
  )
}

function QualificationEvidence({ detail }: { detail: MemecoinDetail }) {
  const q = detail.qualification
  const estimate = detail.historical_estimate
  const p = statusPresentation(q.status)

  // Qualified on a verified / observed market cap.
  if (q.qualified) {
    return (
      <div className={`evidence-card evidence-${p.tone}`}>
        <p className="evidence-headline">
          <span aria-hidden="true">{p.icon}</span> {p.headline}
        </p>
        <div className="detail-grid evidence-grid">
          <Field label="Verified peak MC">{show(q.peak_value, formatUsd)}</Field>
          <Field label="Source">{sourceLabel(q.source)}</Field>
          <Field label="Basis">{basisLabel(q.basis)}</Field>
          <Field label="Confidence">{confidenceLabel(q.confidence)}</Field>
        </div>
      </div>
    )
  }

  // Not qualified — but an FDV estimate exists. Show it, explicitly labelled.
  if (q.status === 'HISTORICAL_ESTIMATE' && estimate) {
    return (
      <div className="evidence-card evidence-estimate">
        <p className="evidence-headline">
          <span aria-hidden="true">🟡</span> Not in the main $5M list — FDV estimate only
        </p>
        <p className="muted">
          This token is <strong>not</strong> in the main qualified list: we have not verified or
          observed a market cap of $5M. We do have a historical <strong>FDV</strong> estimate:
        </p>
        <div className="detail-grid evidence-grid">
          <Field label="Estimated historical FDV">{show(estimate.estimated_fdv_usd, formatUsd)}</Field>
          <Field label="Source">{sourceLabel(estimate.estimate_source)}</Field>
          <Field label="Basis">{basisLabel(estimate.estimate_basis)}</Field>
          <Field label="Confidence">{confidenceLabel(estimate.estimate_confidence)}</Field>
        </div>
        <p className="muted evidence-note">
          <strong>FDV = peak price × total supply.</strong> This is an estimate of fully-diluted
          value, <strong>not</strong> a verified circulating market cap. It does not verify that
          market capitalization reached $5M.
        </p>
      </div>
    )
  }

  // Not qualified, no estimate — unknown / unverified.
  return (
    <div className="evidence-card evidence-unknown">
      <p className="evidence-headline">
        <span aria-hidden="true">⚪</span> Historical peak not verified
      </p>
      <p className="muted">
        A market cap of $5M could not be verified or observed for this token with available data.
        This is <strong>not</strong> a claim that the token never reached $5M.
      </p>
    </div>
  )
}

function RiskAssessmentBlock({ risk }: { risk: RiskAssessment }) {
  if (risk.status === 'pending' || !risk.risk_level) {
    return (
      <div className="placeholder-card">
        <p className="placeholder-lead">This token has not been risk-screened yet.</p>
        <p className="muted">
          Risk screening runs on a schedule after discovery. {risk.disclaimer}
        </p>
      </div>
    )
  }

  const p = riskPresentation(risk.risk_level)
  const groups = groupSignals(risk.signals)

  return (
    <div className={`risk-card risk-card-${p.tone}`}>
      <div className="risk-card-head">
        <RiskChip level={risk.risk_level} />
        <div className="detail-grid risk-headline-grid">
          <Field label="Risk score">
            {risk.risk_score != null ? `${risk.risk_score} / 100` : '—'}
          </Field>
          <Field label="Data completeness">
            {risk.data_completeness != null ? `${Math.round(risk.data_completeness * 100)}%` : '—'}
          </Field>
          <Field label="Last screened">{show(risk.screened_at, formatDateTime)}</Field>
          <Field label="Screening status">{risk.status}</Field>
        </div>
      </div>

      {risk.hard_override_signal && (
        <p className="risk-hard-override">
          Level set by a hard safety filter: <code>{risk.hard_override_signal}</code>.
        </p>
      )}

      {risk.risk_level === 'UNKNOWN' && (
        <p className="muted risk-unknown-note">
          Risk unknown — insufficient security data. This is <strong>not</strong> the same as HIGH
          RISK, and it is not &ldquo;safe&rdquo;.
        </p>
      )}

      {groups.map(([group, signals]) => (
        <div key={group} className="risk-group">
          <h3>{RISK_SIGNAL_GROUP_LABELS[group] ?? group}</h3>
          <ul className="risk-signal-list">
            {signals.map((sig) => (
              <li key={sig.key}>
                <details>
                  <summary>
                    <span aria-hidden="true">{signalStateIcon(sig.state)}</span>{' '}
                    <span className="risk-signal-key">{humanizeKey(sig.key)}</span>
                    {sig.value != null && <span className="risk-signal-value">{sig.value}</span>}
                    <span className={`risk-signal-state risk-signal-state-${sig.state.toLowerCase()}`}>
                      {sig.state}
                    </span>
                  </summary>
                  <div className="risk-signal-body">
                    {sig.explanation && <p>{sig.explanation}</p>}
                    <p className="muted">
                      Source: {sig.source ?? 'internal'}
                      {sig.source_checked_at
                        ? ` · checked ${formatDateTime(sig.source_checked_at)}`
                        : ''}
                      {sig.severity !== 'none' ? ` · severity: ${sig.severity}` : ''}
                    </p>
                  </div>
                </details>
              </li>
            ))}
          </ul>
        </div>
      ))}

      <p className="muted risk-disclaimer">{risk.disclaimer}</p>
    </div>
  )
}

function groupSignals(signals: RiskSignal[]): Array<[string, RiskSignal[]]> {
  const order = [
    'contract_security',
    'exit_safety',
    'holder_distribution',
    'liquidity',
    'pump_dump',
    'market_structure',
    'age',
  ]
  const byGroup = new Map<string, RiskSignal[]>()
  for (const sig of signals) {
    const list = byGroup.get(sig.group) ?? []
    list.push(sig)
    byGroup.set(sig.group, list)
  }
  return order
    .filter((g) => byGroup.has(g))
    .map((g) => [g, byGroup.get(g) as RiskSignal[]])
}

function humanizeKey(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}
function crossingTypeLabel(type: string | null): string {
  switch (type) {
    case 'CURRENT_OBSERVATION':
      return 'Current observation (our own snapshot saw ≥ $5M)'
    case 'HISTORICAL_VERIFIED':
      return 'Historically verified crossing (CoinGecko)'
    default:
      return 'Not yet recorded'
  }
}

function QualificationTimeline({ detail }: { detail: MemecoinDetail }) {
  const t = detail.qualification_timeline
  const currentMc = detail.latest.market_cap

  if (!t.crossed_at) {
    return (
      <div className="placeholder-card">
        <p className="placeholder-lead">No $5M crossing has been recorded for this token yet.</p>
        <p className="muted">
          A crossing is recorded when a verified or observed market cap clears $5M. This is
          <strong> not</strong> a claim that the token never reached $5M.
        </p>
      </div>
    )
  }

  return (
    <div className="timeline-card">
      <div className="detail-grid">
        <Field label="Crossed $5M">{formatDateTime(t.crossed_at)}</Field>
        <Field label="Crossing type">{crossingTypeLabel(t.crossing_type)}</Field>
        <Field label="MC at crossing">{show(t.crossing_market_cap_value, formatUsd)}</Field>
        <Field label="Current MC">{show(currentMc, formatUsd)}</Field>
        <Field label="Peak MC">
          {show(detail.qualification.peak_value ?? detail.observed.peak_market_cap, formatUsd)}
        </Field>
        <Field label="Within recent window">{t.recently_crossed ? 'Yes' : 'No'}</Field>
      </div>

      {t.currently_below_threshold === true && (
        <p className="muted timeline-note">
          Current MC is below $5M, but the token remains qualified because it previously crossed
          the threshold. The floor is a peak rule — we do not re-disqualify on the current price.
        </p>
      )}

      {t.events.length > 1 && (
        <div className="timeline-events">
          <h3>All recorded crossings</h3>
          <ul>
            {t.events.map((event, index) => (
              <li key={`${event.type}-${index}`}>
                <strong>{crossingTypeLabel(event.type)}</strong> — {formatDateTime(event.crossed_at)}
                {event.market_cap_value != null && <> ({formatUsd(event.market_cap_value)})</>}
              </li>
            ))}
          </ul>
          <p className="muted">
            When a historically verified crossing exists, it is the representative one — the earlier
            current-observation record is kept for the history.
          </p>
        </div>
      )}
    </div>
  )
}

function PumpEvents({ events }: { events: PumpIntelligenceEvent[] }) {
  if (events.length === 0) {
    return (
      <div className="placeholder-card">
        <p className="placeholder-lead">No pump events have been detected for this token.</p>
        <p className="muted">
          Our detector has not observed a significant sudden upward move in this token&rsquo;s stored
          observation series.
        </p>
      </div>
    )
  }

  return (
    <ol className="pump-events">
      {events.map((event) => (
        <li key={event.id}>
          <PumpEventCard event={event} />
        </li>
      ))}
    </ol>
  )
}

function PumpEventCard({ event }: { event: PumpIntelligenceEvent }) {
  const ex = event.explanation
  const summaryLabel =
    ex.status === 'completed' && ex.presented
      ? ex.presented.catalyst_label
      : ex.status === 'pending'
        ? 'Explanation pending'
        : ex.status === 'failed'
          ? 'Explanation unavailable'
          : 'No explanation'

  return (
    <article className="pump-event">
      <div className="pump-event-head">
        <span className="pump-event-icon" aria-hidden="true">
          🚀
        </span>
        <div className="pump-event-when">
          <strong>
            {formatDateTime(event.started_at)} → {formatDateTime(event.peak_at)}
          </strong>
        </div>
        <span className={`pump-status pump-status-${event.status}`}>{event.status}</span>
      </div>

      <div className="pump-event-metrics">
        <Metric label="Market cap" value={formatPercentCompact(event.market_cap_change_pct)} />
        <Metric label="Price" value={formatPercentCompact(event.price_change_pct)} />
        <Metric label="Detection score" value={show(event.detection_score, formatInteger)} />
        <Metric label="Confidence" value={confidenceLabel(event.detection_confidence)} />
      </div>

      <details className="pump-explain">
        <summary>Why did this coin pump? — {summaryLabel}</summary>
        <PumpExplanationBody explanation={ex} />
      </details>
    </article>
  )
}

function PumpExplanationBody({ explanation: ex }: { explanation: PumpExplanation }) {
  if (ex.status === 'pending') {
    return <p className="muted pump-pending">Explanation pending.</p>
  }

  if (ex.status !== 'completed' || !ex.presented) {
    return (
      <p className="muted pump-pending">
        Explanation unavailable. We show nothing here rather than a guessed reason.
      </p>
    )
  }

  const p = ex.presented
  const citedById = new Map(ex.cited_evidence.map((e) => [e.id, e]))

  return (
    <div className="pump-explanation">
      <p className="pump-headline">{p.headline}</p>
      {p.summary && <p className="pump-summary">{p.summary}</p>}

      {p.evidence_lines.length > 0 && (
        <div className="pump-evidence">
          <h3>Evidence</h3>
          <ul>
            {p.evidence_lines.map((line, i) => (
              <li key={i}>
                <span>{line.statement}</span>{' '}
                {line.evidence_ids.map((id) => (
                  <EvidenceRef key={id} id={id} evidence={citedById.get(id)} />
                ))}
              </li>
            ))}
          </ul>
        </div>
      )}

      {p.secondary_signals.length > 0 && (
        <div className="pump-evidence">
          <h3>Secondary signals</h3>
          <ul>
            {p.secondary_signals.map((sig, i) => (
              <li key={i}>
                <strong>{sig.label}:</strong> <span>{sig.statement}</span>{' '}
                {sig.evidence_ids.map((id) => (
                  <EvidenceRef key={id} id={id} evidence={citedById.get(id)} />
                ))}
              </li>
            ))}
          </ul>
        </div>
      )}

      <p className="pump-confidence">
        Explanation confidence: <strong>{p.confidence_label}</strong>
      </p>

      {p.caveats.length > 0 && (
        <div className="pump-caveats">
          <h3>Caveats</h3>
          <ul>
            {p.caveats.map((c, i) => (
              <li key={i}>{c}</li>
            ))}
          </ul>
        </div>
      )}

      {p.unknowns.length > 0 && (
        <div className="pump-caveats">
          <h3>Unknowns</h3>
          <ul>
            {p.unknowns.map((u, i) => (
              <li key={i}>{u}</li>
            ))}
          </ul>
        </div>
      )}

      <p className="muted pump-provenance">
        Generated {formatDateTime(ex.generated_at)}
        {ex.model_provider ? ` · ${ex.model_provider}` : ''}. The model only interprets the evidence
        listed above — it does not add facts, and temporal association does not prove causation.
      </p>
    </div>
  )
}

function EvidenceRef({ id, evidence }: { id: number; evidence: CitedEvidence | undefined }) {
  if (!evidence) {
    return <span className="evidence-ref">[Evidence #{id}]</span>
  }

  return (
    <details className="evidence-ref-details">
      <summary>[Evidence #{id}]</summary>
      <div className="evidence-ref-body">
        <EvidenceBody evidence={evidence} />
      </div>
    </details>
  )
}

function EvidenceBody({ evidence }: { evidence: CitedEvidence }) {
  return (
    <div className="evidence-ref-inner">
      <p>
        <strong>
          Evidence #{evidence.id} · {evidenceCategoryLabel(evidence.category)}
        </strong>
        {evidence.title ? ` — ${evidence.title}` : ''}
      </p>
      <p>{evidence.summary}</p>
      <dl>
        <div>
          <dt>Source</dt>
          <dd>{evidence.source}</dd>
        </div>
        {evidence.published_at && (
          <div>
            <dt>Published</dt>
            <dd>{formatDateTime(evidence.published_at)}</dd>
          </div>
        )}
        {evidence.observed_at && (
          <div>
            <dt>Observed</dt>
            <dd>{formatDateTime(evidence.observed_at)}</dd>
          </div>
        )}
        <div>
          <dt>Relevance</dt>
          <dd>
            {evidence.relevance_score}/100 · {confidenceLabel(evidence.confidence)} confidence
          </dd>
        </div>
      </dl>
      {evidence.source_url && (
        <p>
          <a
            href={evidence.source_url}
            target="_blank"
            rel="noreferrer noopener"
            aria-label={`${evidence.source_url} (opens in a new tab)`}
          >
            {hostOf(evidence.source_url)} <span aria-hidden="true">↗</span>
          </a>
        </p>
      )}
    </div>
  )
}

function hostOf(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

function evidenceCategoryLabel(category: string): string {
  const map: Record<string, string> = {
    MARKET: 'Market observation',
    RELATED_TOKEN: 'Related token',
    NEWS: 'News',
    ORIGIN: 'Token origin',
    TOKEN_METADATA: 'Token metadata',
  }
  return map[category] ?? category
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="detail-section">
      <h2>{title}</h2>
      {children}
    </section>
  )
}

function Stat({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <div className="stat-card">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
      {note && <span className="stat-note">{note}</span>}
    </div>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="pump-metric">
      <span className="pump-metric-label">{label}</span>
      <span className="pump-metric-value">{value}</span>
    </div>
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
