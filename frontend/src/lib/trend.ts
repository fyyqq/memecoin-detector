import type { ChainBucket, Timeframe } from '../types/memecoin'
import { CHAIN_BUCKET_LABEL } from '../types/memecoin'

export const TIMEFRAME_LABEL: Record<Timeframe, string> = {
  '6h': '6H',
  '24h': '24H',
}

/** Chain-bucket filter options for the trending UI ("All" + the five buckets). */
export const TREND_CHAIN_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'All' },
  ...(['solana', 'robinhood', 'bsc', 'base', 'other'] as ChainBucket[]).map((bucket) => ({
    value: bucket,
    label: CHAIN_BUCKET_LABEL[bucket],
  })),
]

/**
 * Tone for the tracked-trend-score pill. Higher = hotter. This is a relative
 * "attention" score, NOT a safety or quality signal.
 */
export function trendTone(score: number | null): 'hot' | 'warm' | 'mild' | 'cool' {
  if (score === null) return 'cool'
  if (score >= 70) return 'hot'
  if (score >= 50) return 'warm'
  if (score >= 30) return 'mild'
  return 'cool'
}

export function formatTrendScore(score: number | null): string {
  return score === null ? '—' : String(Math.round(score))
}
