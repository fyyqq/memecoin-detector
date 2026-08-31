import type {
  NarrativeOriginSection,
  NarrativePopularitySection,
  NarrativeSectionStatus,
  NarrativeSource,
  NarrativeTimelineType,
  TokenNarrative,
} from '../types/memecoinDetail'
import { formatDateTime } from '../lib/format'

const ORIGIN_TYPE_LABELS: Record<string, string> = {
  COMMUNITY_MEME: 'Community meme',
  INTERNET_MEME: 'Internet meme',
  CELEBRITY_MEME: 'Celebrity meme',
  POLITICAL_MEME: 'Political meme',
  CULTURAL_REFERENCE: 'Cultural reference',
  VIRAL_EVENT: 'Viral event',
  ANIMAL_MEME: 'Animal meme',
  NARRATIVE_TOKEN: 'Narrative token',
  UTILITY_PLUS_MEME: 'Utility + meme',
  UNKNOWN: 'Not established',
}

const TIMELINE_TYPE_LABELS: Record<NarrativeTimelineType, string> = {
  MEME_ORIGIN: 'Meme origin',
  LAUNCH: 'Launch',
  MEDIA_ATTENTION: 'Media attention',
  SOCIAL_ATTENTION: 'Social attention',
  CELEBRITY_ATTENTION: 'Celebrity attention',
  EXCHANGE_LISTING: 'Exchange listing',
  NARRATIVE_EVENT: 'Narrative event',
  RELATED_TOKEN: 'Related token',
  COMMUNITY_EVENT: 'Community event',
  MARKET_ACTIVITY: 'Market activity',
  OTHER: 'Other',
}

const SOURCE_TYPE_LABELS: Record<string, string> = {
  official: 'Official',
  news: 'News',
  social: 'Social',
  market: 'Market data',
  community: 'Community',
  reference: 'Reference',
}

function confidenceLabel(c: string | null): string {
  if (!c) return '—'
  return c.charAt(0).toUpperCase() + c.slice(1)
}

/** Neutral message per non-completed state — never a stack trace. */
function stateNote(status: NarrativeSectionStatus): string | null {
  switch (status) {
    case 'pending':
      return 'Narrative research pending.'
    case 'partial':
      return 'Some narrative evidence was unavailable.'
    case 'failed':
      return 'Narrative research unavailable.'
    default:
      return null
  }
}

export function TokenNarrativeSection({ narrative }: { narrative: TokenNarrative }) {
  const sourceById = new Map(narrative.sources.map((s) => [s.id, s]))

  return (
    <>
      <p className="muted detail-note">
        Two separate evidence-grounded questions. The model interprets collected sources and our
        own market records — it never browses, never invents sources or dates, and never asserts an
        unsupported reason. Every factual line cites its sources.
      </p>

      <div className="narrative-grid">
        <PopularityColumn section={narrative.popularity} sourceById={sourceById} />
        <OriginColumn section={narrative.origin} sourceById={sourceById} />
      </div>

      {narrative.generated_at && (
        <p className="muted detail-note">
          Generated {formatDateTime(narrative.generated_at)}
          {narrative.model_provider ? ` · ${narrative.model_provider}` : ''}
          {narrative.research_providers_used && narrative.research_providers_used.length > 0
            ? ` · sources via ${narrative.research_providers_used.join(', ')}`
            : ''}
          . Temporal association between market moves and events does not establish causation.
        </p>
      )}
    </>
  )
}

function PopularityColumn({
  section,
  sourceById,
}: {
  section: NarrativePopularitySection
  sourceById: Map<number, NarrativeSource>
}) {
  const note = stateNote(section.status)
  const timeline = section.timeline ?? []
  const factors = section.dominant_factors ?? []

  return (
    <article className="narrative-card">
      <h3>Why it became popular</h3>

      {section.status === 'completed' ? (
        <>
          {section.headline && <p className="narrative-headline">{section.headline}</p>}
          {section.summary && <p className="narrative-summary">{section.summary}</p>}

          {timeline.length > 0 && (
            <div className="narrative-block">
              <h4>Timeline</h4>
              <ol className="narrative-timeline">
                {timeline.map((entry, i) => (
                  <li key={i}>
                    <span className="narrative-timeline-date">
                      {entry.date ? entry.date : 'date unknown'}
                    </span>
                    <span className="narrative-chip">{TIMELINE_TYPE_LABELS[entry.type]}</span>
                    <strong>{entry.title}</strong>
                    <p>{entry.description}</p>
                    <SourceRefs ids={entry.source_ids} sourceById={sourceById} />
                  </li>
                ))}
              </ol>
            </div>
          )}

          {factors.length > 0 && (
            <div className="narrative-block">
              <h4>Dominant factors</h4>
              <ul>
                {factors.map((f, i) => (
                  <li key={i}>{f}</li>
                ))}
              </ul>
            </div>
          )}

          <NarrativeMeta section={section} />
        </>
      ) : (
        <p className="muted narrative-pending">{note}</p>
      )}

      <SourceList
        title="Sources"
        sources={[...sourceById.values()].filter((s) => s.section === 'popularity')}
      />
    </article>
  )
}

function OriginColumn({
  section,
  sourceById,
}: {
  section: NarrativeOriginSection
  sourceById: Map<number, NarrativeSource>
}) {
  const note = stateNote(section.status)
  const facts = section.supporting_facts ?? []
  const originType = section.origin_type ?? null

  return (
    <article className="narrative-card">
      <h3>Why it was created</h3>

      {section.status === 'completed' ? (
        <>
          {originType && (
            <p className="narrative-chip narrative-chip-lg">
              {ORIGIN_TYPE_LABELS[originType] ?? originType}
            </p>
          )}
          {section.headline && <p className="narrative-headline">{section.headline}</p>}
          {section.summary && <p className="narrative-summary">{section.summary}</p>}

          {facts.length > 0 && (
            <div className="narrative-block">
              <h4>Supporting facts</h4>
              <ul className="narrative-facts">
                {facts.map((fact, i) => (
                  <li key={i}>
                    <span>{fact.statement}</span>{' '}
                    <SourceRefs ids={fact.source_ids} sourceById={sourceById} />
                  </li>
                ))}
              </ul>
            </div>
          )}

          {originType === 'UNKNOWN' && facts.length === 0 && (
            <p className="muted">
              Not enough reliable evidence to establish the origin. This is <strong>not</strong> a
              claim about creator intent.
            </p>
          )}

          <NarrativeMeta section={section} />
        </>
      ) : (
        <p className="muted narrative-pending">{note}</p>
      )}

      <SourceList
        title="Sources"
        sources={[...sourceById.values()].filter((s) => s.section === 'origin')}
      />
    </article>
  )
}

function NarrativeMeta({
  section,
}: {
  section: NarrativeOriginSection | NarrativePopularitySection
}) {
  return (
    <>
      <p className="narrative-confidence">
        Confidence: <strong>{confidenceLabel(section.confidence)}</strong>
      </p>
      {section.caveats.length > 0 && (
        <div className="narrative-block">
          <h4>Caveats</h4>
          <ul>
            {section.caveats.map((c, i) => (
              <li key={i}>{c}</li>
            ))}
          </ul>
        </div>
      )}
      {section.unknowns.length > 0 && (
        <div className="narrative-block">
          <h4>Unknowns</h4>
          <ul>
            {section.unknowns.map((u, i) => (
              <li key={i}>{u}</li>
            ))}
          </ul>
        </div>
      )}
    </>
  )
}

function SourceRefs({
  ids,
  sourceById,
}: {
  ids: number[]
  sourceById: Map<number, NarrativeSource>
}) {
  return (
    <>
      {ids.map((id) => {
        const source = sourceById.get(id)
        if (!source) {
          return (
            <span key={id} className="evidence-ref">
              [S{id}]
            </span>
          )
        }
        return (
          <details key={id} className="evidence-ref-details">
            <summary>[S{id}]</summary>
            <div className="evidence-ref-body">
              <SourceBody source={source} />
            </div>
          </details>
        )
      })}
    </>
  )
}

function SourceList({ title, sources }: { title: string; sources: NarrativeSource[] }) {
  if (sources.length === 0) {
    return (
      <div className="narrative-block">
        <h4>{title}</h4>
        <p className="muted">No sources recorded.</p>
      </div>
    )
  }

  return (
    <details className="narrative-sources">
      <summary>
        {title} ({sources.length})
      </summary>
      <div className="narrative-sources-body">
        {sources
          .slice()
          .sort((a, b) => b.relevance_score - a.relevance_score)
          .map((source) => (
            <div key={source.id} className="evidence-ref-body evidence-ref-body-standalone">
              <SourceBody source={source} />
            </div>
          ))}
      </div>
    </details>
  )
}

function SourceBody({ source }: { source: NarrativeSource }) {
  return (
    <div className="evidence-ref-inner">
      <p>
        <strong>
          S{source.id} · {SOURCE_TYPE_LABELS[source.source_type] ?? source.source_type}
        </strong>
        {source.source_name ? ` — ${source.source_name}` : ''}
      </p>
      {source.title && <p>{source.title}</p>}
      <p>{source.claim}</p>
      <dl>
        {source.published_at && (
          <div>
            <dt>Published</dt>
            <dd>{formatDateTime(source.published_at)}</dd>
          </div>
        )}
        <div>
          <dt>Confidence</dt>
          <dd>{confidenceLabel(source.confidence)}</dd>
        </div>
      </dl>
      {source.source_url && (
        <p>
          <a
            href={source.source_url}
            target="_blank"
            rel="noreferrer noopener"
            aria-label={`${source.source_url} (opens in a new tab)`}
          >
            Open source <span aria-hidden="true">↗</span>
          </a>
        </p>
      )}
    </div>
  )
}
