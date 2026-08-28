/** Formatting helpers for the dashboard. All tolerate `null`. */

export function formatUsd(value: number | null): string {
  if (value === null || Number.isNaN(value)) return '—'
  if (value === 0) return '$0'

  const abs = Math.abs(value)
  const units: Array<[number, string]> = [
    [1e12, 'T'],
    [1e9, 'B'],
    [1e6, 'M'],
    [1e3, 'K'],
  ]

  for (const [threshold, suffix] of units) {
    if (abs >= threshold) {
      const scaled = value / threshold
      const digits = Math.abs(scaled) >= 100 || Number.isInteger(scaled) ? 0 : 1
      return `$${scaled.toFixed(digits)}${suffix}`
    }
  }

  return `$${value.toFixed(0)}`
}

export function formatAgeDays(days: number | null): string {
  if (days === null || Number.isNaN(days)) return '—'
  if (days < 1) return '<1d'
  return `${Math.floor(days)}d`
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatRelativeTime(iso: string | null): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  const seconds = Math.round((Date.now() - date.getTime()) / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const daysAgo = Math.round(hours / 24)
  return `${daysAgo}d ago`
}
