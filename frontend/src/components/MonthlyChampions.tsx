import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type {
  ChainBucket,
  MonthlyChampionBucket,
  MonthlyChampionEntry,
  MonthlyChampionMonth,
} from '../types/memecoin'
import { CHAIN_BUCKET_LABEL, CHAIN_BUCKETS } from '../types/memecoin'
import { formatInteger, formatUsd } from '../lib/format'

interface MonthlyChampionsProps {
  months: MonthlyChampionMonth[]
  year: number
  loading: boolean
  error: string
  onRetry: () => void
}

type BucketFilter = 'all' | ChainBucket

const MEDALS = ['🥇', '🥈', '🥉']

/**
 * "Monthly Top Memecoins" — a 3×4 year calendar. Each month card shows the TOP 3
 * memecoins in EACH of the five chain buckets (Solana / Robinhood / BSC / Base /
 * Other), ranked by real participation: holder count (0.40) + representative
 * monthly volume (0.35) + month-peak observed/verified market cap (0.25). Market
 * cap is supporting evidence — it cannot dominate. Not a "best investment" — a
 * monthly participation record per chain.
 *
 * A chain filter narrows every month card to a single bucket's Top 3.
 */
export function MonthlyChampions({ months, year, loading, error, onRetry }: MonthlyChampionsProps) {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<BucketFilter>('all')

  const open = (entry: MonthlyChampionEntry) => {
    // Only tokens we actually track (token.id) have a detail page. A
    // historically-backfilled entry we do not track is display-only.
    const token = entry.token
    if (!token || token.id == null) return
    navigate(
      `/memecoin/${encodeURIComponent(token.chain_id)}/${encodeURIComponent(token.token_address)}`,
    )
  }

  return (
    <section className="monthly-champions">
      <div className="section-heading">
        <h2>🏆 Monthly Top Memecoins</h2>
        <span className="muted">{year}</span>
      </div>
      <p className="muted detail-note">
        Top 3 memecoins per chain bucket per month, ranked by real participation — holder count
        (40%), representative monthly volume (35%) and month-peak observed/verified market cap
        (25%), log-normalized. Market cap is supporting evidence and cannot dominate. Completed
        months before our detector launched are backfilled from researched historical sources;
        where evidence is incomplete an entry shows lower confidence, and a bucket with no
        defensible candidate shows &ldquo;No verified result&rdquo; — never a fabricated position or
        a claimed exact DexScreener rank. Not a prediction of returns, not an investment
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

const MONTH_STATUS_LABEL: Record<string, string> = {
  provisional: 'Provisional',
  finalized: 'Finalized',
  future: 'Upcoming',
}

const SOURCE_TYPE_LABEL: Record<string, string> = {
  internal_observed: 'Internal observed data',
  exact_dexscreener_rank: 'DexScreener historical rank',
  best_supported_historical_performer: 'Historical research',
  dexscreener: 'DexScreener',
  web_research: 'Web research',
  other_verified_source: 'Verified source',
}

function emptyLabel(bucket: MonthlyChampionBucket): string {
  switch (bucket.status) {
    case 'future':
      return 'No results yet'
    case 'no_verified_result':
      return 'No verified result'
    default:
      return 'Awaiting data'
  }
}

function MonthCard({
  month,
  filter,
  onOpen,
}: {
  month: MonthlyChampionMonth
  filter: BucketFilter
  onOpen: (entry: MonthlyChampionEntry) => void
}) {
  const buckets =
    filter === 'all' ? CHAIN_BUCKETS.map((b) => month.champions[b]) : [month.champions[filter]]

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
          <li key={bucket.chain_bucket} className="champion-bucket-block">
            <span className="champion-bucket-name">{CHAIN_BUCKET_LABEL[bucket.chain_bucket]}</span>
            {bucket.entries.length === 0 ? (
              <span className="champion-bucket-empty muted">{emptyLabel(bucket)}</span>
            ) : (
              <ol className="champion-entries">
                {bucket.entries.map((entry) => (
                  <EntryRow
                    key={entry.rank}
                    entry={entry}
                    single={filter !== 'all'}
                    onOpen={onOpen}
                  />
                ))}
              </ol>
            )}
          </li>
        ))}
      </ul>
    </article>
  )
}

function EntryRow({
  entry,
  single,
  onOpen,
}: {
  entry: MonthlyChampionEntry
  single: boolean
  onOpen: (entry: MonthlyChampionEntry) => void
}) {
  const { token, performance } = entry
  const isLink = token != null && token.id != null
  const medal = MEDALS[entry.rank - 1] ?? `#${entry.rank}`

  return (
    <li
      className={`champion-entry${isLink ? ' champion-entry-link' : ''}`}
      {...(isLink
        ? {
            role: 'link' as const,
            tabIndex: 0,
            'aria-label': `View ${token.symbol ?? 'entry'} — rank ${entry.rank}`,
            onClick: () => onOpen(entry),
            onKeyDown: (event: React.KeyboardEvent) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                onOpen(entry)
              }
            },
          }
        : {})}
    >
      <span className="champion-entry-head">
        <span aria-hidden="true">{medal}</span> ${token?.symbol ?? '—'}
        {entry.age_uncertain && (
          <span
            className="champion-tag champion-tag-warn"
            title="Launch / pool age could not be established from evidence"
          >
            age uncertain
          </span>
        )}
      </span>
      <span className="champion-entry-stats muted">
        {performance.score != null ? performance.score.toFixed(1) : '—'}
        {' · '}
        {performance.market_cap != null ? formatUsd(performance.market_cap) : '—'} MC
        {' · '}
        {performance.holder_count != null
          ? `${formatInteger(performance.holder_count)} holders`
          : 'holders unknown'}
      </span>
      {single && (
        <span className="champion-entry-meta muted">
          {performance.monthly_volume != null ? `${formatUsd(performance.monthly_volume)} vol` : 'vol —'}
          {' · '}
          {token?.chain_id}
          {entry.confidence ? ` · ${entry.confidence} confidence` : ''}
          {entry.source_type
            ? ` · ${SOURCE_TYPE_LABEL[entry.source_type] ?? entry.source_type}`
            : ''}
        </span>
      )}
    </li>
  )
}
