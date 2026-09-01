import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type {
  ChainBucket,
  MonthlyChampionBucket,
  MonthlyChampionMonth,
} from '../types/memecoin'
import { CHAIN_BUCKET_LABEL, CHAIN_BUCKETS } from '../types/memecoin'
import { formatPercentCompact, formatUsd } from '../lib/format'

interface MonthlyChampionsProps {
  months: MonthlyChampionMonth[]
  year: number
  loading: boolean
  error: string
  onRetry: () => void
}

type BucketFilter = 'all' | ChainBucket

/**
 * "Monthly Chain Champions" — a 3×4 year calendar. Each month card shows the
 * top-1 memecoin in EACH of the five chain buckets (Solana / Robinhood / BSC /
 * Base / Other), by observed market-cap growth within the eligible universe.
 * Not a "best investment" — a monthly observed-performance record per chain.
 *
 * A chain filter narrows every month card to a single bucket.
 */
export function MonthlyChampions({ months, year, loading, error, onRetry }: MonthlyChampionsProps) {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<BucketFilter>('all')

  const open = (bucket: MonthlyChampionBucket) => {
    // Only tokens we actually track (token.id) have a detail page. A
    // historically-backfilled champion we do not track is display-only.
    if (!bucket.token || bucket.token.id == null) return
    navigate(
      `/memecoin/${encodeURIComponent(bucket.token.chain_id)}/${encodeURIComponent(
        bucket.token.token_address,
      )}`,
    )
  }

  return (
    <section className="monthly-champions">
      <div className="section-heading">
        <h2>🏆 Monthly Chain Champions</h2>
        <span className="muted">{year}</span>
      </div>
      <p className="muted detail-note">
        Top-1 performing memecoin per chain bucket per month, by observed market-cap growth within
        the eligible universe. Completed months before our detector launched are backfilled from
        researched historical market sources; where evidence is incomplete a bucket shows
        &ldquo;Best-supported&rdquo; or &ldquo;No verified champion&rdquo; — never a fabricated
        winner or a claimed exact DexScreener rank. Not a prediction of returns, not an investment
        recommendation.
      </p>

      <div className="champion-filter" role="group" aria-label="Chain bucket filter">
        <button
          type="button"
          className={filter === 'all' ? 'is-active' : ''}
          onClick={() => setFilter('all')}
        >
          All Chains
        </button>
        {CHAIN_BUCKETS.map((bucket) => (
          <button
            key={bucket}
            type="button"
            className={filter === bucket ? 'is-active' : ''}
            onClick={() => setFilter(bucket)}
          >
            {CHAIN_BUCKET_LABEL[bucket]}
          </button>
        ))}
      </div>

      {loading && months.length === 0 && <p className="state">Loading…</p>}

      {error && (
        <div className="state state-error">
          <p>{error}</p>
          <button type="button" onClick={onRetry}>
            Try again
          </button>
        </div>
      )}

      {months.length > 0 && (
        <div className="champion-grid">
          {months.map((month) => (
            <MonthCard key={`${month.year}-${month.month}`} month={month} filter={filter} onOpen={open} />
          ))}
        </div>
      )}
    </section>
  )
}

const BUCKET_STATUS_LABEL: Record<string, string> = {
  provisional: 'Provisional',
  finalized: 'Finalized',
  best_supported_candidate: 'Best-supported',
  no_verified_champion: 'No verified champion',
  future: 'No champion yet',
}

const SOURCE_TYPE_LABEL: Record<string, string> = {
  internal_observed: 'Internal observed data',
  exact_dexscreener_rank: 'DexScreener historical rank',
  best_supported_historical_performer: 'Historical research',
  dexscreener: 'DexScreener',
  web_research: 'Web research',
  other_verified_source: 'Verified source',
}

const MONTH_STATUS_LABEL: Record<string, string> = {
  provisional: 'Provisional',
  finalized: 'Finalized',
  future: 'Upcoming',
}

function MonthCard({
  month,
  filter,
  onOpen,
}: {
  month: MonthlyChampionMonth
  filter: BucketFilter
  onOpen: (bucket: MonthlyChampionBucket) => void
}) {
  const buckets =
    filter === 'all'
      ? CHAIN_BUCKETS.map((b) => month.champions[b])
      : [month.champions[filter]]

  return (
    <article className={`champion-card champion-card-${month.status}`}>
      <header className="champion-month">
        {month.month_name}
        <span className={`champion-status champion-status-${month.status}`}>
          {MONTH_STATUS_LABEL[month.status] ?? month.status}
        </span>
      </header>

      <ul className={`champion-buckets${filter !== 'all' ? ' champion-buckets-single' : ''}`}>
        {buckets.map((bucket) => (
          <BucketRow key={bucket.chain_bucket} bucket={bucket} single={filter !== 'all'} onOpen={onOpen} />
        ))}
      </ul>
    </article>
  )
}

function BucketRow({
  bucket,
  single,
  onOpen,
}: {
  bucket: MonthlyChampionBucket
  single: boolean
  onOpen: (bucket: MonthlyChampionBucket) => void
}) {
  const { token, performance } = bucket
  const hasChampion = token !== null
  const isLink = token !== null && token.id != null
  const growth = performance?.market_cap_growth_pct
  const label = CHAIN_BUCKET_LABEL[bucket.chain_bucket]

  return (
    <li
      className={`champion-bucket-row${isLink ? ' champion-bucket-link' : ''}`}
      {...(isLink
        ? {
            role: 'link' as const,
            tabIndex: 0,
            'aria-label': `View ${token.symbol ?? 'champion'} — ${label}`,
            onClick: () => onOpen(bucket),
            onKeyDown: (event: React.KeyboardEvent) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                onOpen(bucket)
              }
            },
          }
        : {})}
    >
      <span className="champion-bucket-name">{label}</span>

      {hasChampion ? (
        <span className="champion-bucket-body">
          <span className="champion-bucket-token">
            <span aria-hidden="true">🥇</span> ${token.symbol ?? '—'}
            {bucket.status === 'best_supported_candidate' && (
              <span className="champion-tag" title="A real token led this bucket but historical evidence is incomplete">
                best-supported
              </span>
            )}
            {bucket.age_uncertain && (
              <span className="champion-tag champion-tag-warn" title="Launch / pool age could not be established from evidence">
                age uncertain
              </span>
            )}
          </span>
          <span className="champion-bucket-growth">
            {growth != null ? `${formatPercentCompact(growth)} MC growth` : 'MC growth —'}
          </span>
          {single && (
            <span className="champion-bucket-meta muted">
              Peak {performance?.peak_market_cap != null ? formatUsd(performance.peak_market_cap) : '—'}
              {' · '}
              {token.chain_id}
              {bucket.confidence ? ` · ${bucket.confidence} confidence` : ''}
              {bucket.source_type
                ? ` · ${SOURCE_TYPE_LABEL[bucket.source_type] ?? bucket.source_type}`
                : ''}
            </span>
          )}
        </span>
      ) : (
        <span className="champion-bucket-empty muted">{BUCKET_STATUS_LABEL[bucket.status] ?? bucket.status}</span>
      )}
    </li>
  )
}
