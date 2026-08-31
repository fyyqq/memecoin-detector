import type { QualificationStatus } from '../types/memecoinDetail'
import { statusPresentation } from '../lib/qualification'

interface QualificationBadgeProps {
  status: QualificationStatus
  className?: string
}

/** Compact pill: CURRENT / VERIFIED / ESTIMATE / UNKNOWN. */
export function QualificationBadge({ status, className }: QualificationBadgeProps) {
  const { badge, tone } = statusPresentation(status)

  return (
    <span
      className={`qual-badge qual-badge-${tone}${className ? ` ${className}` : ''}`}
      title={`Qualification: ${status}`}
    >
      {badge}
    </span>
  )
}
