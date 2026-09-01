import type { RiskLevel } from '../types/memecoin'

/**
 * Display metadata for a risk level (Step 24). Never "SAFE" / "guaranteed" —
 * LOWER / MEDIUM / HIGH RISK, CRITICAL — AVOID, RISK UNKNOWN.
 */
interface RiskPresentation {
  label: string
  tone: 'lower' | 'medium' | 'high' | 'critical' | 'unknown'
  title: string
}

const RISK: Record<RiskLevel, RiskPresentation> = {
  LOWER: {
    label: 'LOWER RISK',
    tone: 'lower',
    title: 'Lower risk — no automated red flags in the checks we could perform. Not a guarantee of safety.',
  },
  MEDIUM: {
    label: 'MEDIUM RISK',
    tone: 'medium',
    title: 'Medium risk — some soft warning signals. Not a guarantee of safety.',
  },
  HIGH: {
    label: 'HIGH RISK',
    tone: 'high',
    title: 'High risk — one or more serious safety flags. Shown for transparency.',
  },
  CRITICAL: {
    label: 'CRITICAL — AVOID',
    tone: 'critical',
    title: 'Critical risk — a hard safety failure (e.g. honeypot, 100% sell tax, mutable balances).',
  },
  UNKNOWN: {
    label: 'RISK UNKNOWN',
    tone: 'unknown',
    title: 'Risk unknown — insufficient security data to screen this token. Not the same as HIGH RISK, and not "safe".',
  },
}

export function riskPresentation(level: RiskLevel | null): RiskPresentation {
  return level ? RISK[level] : { label: 'NOT SCREENED', tone: 'unknown', title: 'This token has not been risk-screened yet.' }
}

const SIGNAL_STATE_ICON: Record<string, string> = {
  MEASURED: '✅',
  BAD: '⚠',
  UNKNOWN: '❓',
  NOT_AVAILABLE: '—',
}

export function signalStateIcon(state: string): string {
  return SIGNAL_STATE_ICON[state] ?? '❓'
}

export const RISK_SIGNAL_GROUP_LABELS: Record<string, string> = {
  contract_security: 'Contract Security',
  exit_safety: 'Exit Safety',
  holder_distribution: 'Holder Distribution',
  liquidity: 'Liquidity',
  pump_dump: 'Pump-Dump',
  market_structure: 'Market Structure',
  age: 'Age',
}
