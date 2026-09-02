/**
 * Header chain selector — REAL blockchain identities.
 *
 * The values are the DexScreener `chain_id`s the application recognises: the
 * chains mapped in `backend/config/historical.php` `chain_map` (ethereum, solana,
 * bsc, base, arbitrum, polygon, avalanche, optimism, pulsechain) plus
 * `robinhood`. The backend accepts any valid chain id — this list is the UI
 * convenience for the common ones.
 *
 * NOTE: this is NOT the Monthly "Other" display bucket. "Other" is a
 * `monthly_rankings.chain_bucket` grouping used only by "Monthly Top Memecoins";
 * it is deliberately absent here.
 */

const CHAIN_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'All Chains' },
  { value: 'solana', label: 'Solana' },
  { value: 'ethereum', label: 'Ethereum' },
  { value: 'bsc', label: 'BSC' },
  { value: 'base', label: 'Base' },
  { value: 'robinhood', label: 'Robinhood' },
  { value: 'arbitrum', label: 'Arbitrum' },
  { value: 'polygon', label: 'Polygon' },
  { value: 'avalanche', label: 'Avalanche' },
  { value: 'optimism', label: 'Optimism' },
  { value: 'pulsechain', label: 'PulseChain' },
]

interface ChainFilterProps {
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}

export function ChainFilter({ value, onChange, disabled }: ChainFilterProps) {
  return (
    <label className="chain-filter">
      <select
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
      >
        {CHAIN_OPTIONS.map((option) => (
          <option key={option.value || 'all'} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}
