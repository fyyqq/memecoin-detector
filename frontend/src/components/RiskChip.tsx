import type { RiskLevel } from '../types/memecoin'
import { riskPresentation } from '../lib/risk'

interface RiskChipProps {
  level: RiskLevel | null
  score?: number | null
  className?: string
}

/**
 * Compact risk pill (Step 24). LOWER / MEDIUM on the main list; HIGH / CRITICAL
 * / RISK UNKNOWN on Risk Watch. Never renders "SAFE".
 */
export function RiskChip({ level, score, className }: RiskChipProps) {
  const { label, tone, title } = riskPresentation(level)

  return (
    <span
      className={`risk-chip risk-chip-${tone}${className ? ` ${className}` : ''}`}
      title={title}
    >
      {label}
      {typeof score === 'number' && <span className="risk-chip-score">{score}</span>}
    </span>
  )
}
