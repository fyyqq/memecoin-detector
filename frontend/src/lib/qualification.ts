import type { QualificationStatus } from '../types/memecoinDetail'

/** Display metadata for each qualification status. Never invents a reason. */
interface StatusPresentation {
  /** Compact dashboard badge label. */
  badge: string
  icon: string
  /** Detail-page headline for "Why is this token on the list?" */
  headline: string
  tone: 'positive' | 'estimate' | 'unknown'
}

const STATUS: Record<QualificationStatus, StatusPresentation> = {
  CURRENT_OBSERVATION: {
    badge: 'CURRENT',
    icon: '✅',
    headline: 'Crossed $5M in detector observation',
    tone: 'positive',
  },
  HISTORICAL_VERIFIED: {
    badge: 'VERIFIED',
    icon: '✅',
    headline: 'Historical peak verified',
    tone: 'positive',
  },
  HISTORICAL_ESTIMATE: {
    badge: 'FDV ESTIMATE',
    icon: '🟡',
    headline: 'Not in the main $5M list — FDV estimate only',
    tone: 'estimate',
  },
  UNKNOWN: {
    badge: 'UNKNOWN',
    icon: '⚪',
    headline: 'Historical peak not verified',
    tone: 'unknown',
  },
}

export function statusPresentation(status: QualificationStatus): StatusPresentation {
  return STATUS[status]
}

/** Provider slug → display name. Unknown slugs pass through unchanged. */
export function sourceLabel(source: string | null): string {
  switch (source) {
    case 'dexscreener':
      return 'DexScreener'
    case 'coingecko':
      return 'CoinGecko'
    case 'geckoterminal':
      return 'GeckoTerminal'
    default:
      return source ?? 'Unavailable'
  }
}

/** Evidence basis slug → display label. */
export function basisLabel(basis: string | null): string {
  switch (basis) {
    case 'current_market_cap':
      return 'Current market cap'
    case 'market_cap':
      return 'Historical market cap'
    case 'fdv_total_supply':
      return 'FDV × total supply'
    default:
      return basis ?? 'Unavailable'
  }
}

export function confidenceLabel(confidence: string | null): string {
  if (!confidence) return 'Unavailable'
  return confidence.charAt(0).toUpperCase() + confidence.slice(1)
}
