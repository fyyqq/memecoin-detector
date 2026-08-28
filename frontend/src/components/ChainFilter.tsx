/**
 * Chain selector. Offers a few common chains plus "All" / "Other" — but the
 * backend accepts any valid chain id, this list is only a UI convenience.
 */

const CHAIN_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'All Chains' },
  { value: 'solana', label: 'Solana' },
  { value: 'ethereum', label: 'Ethereum' },
  { value: 'bsc', label: 'BSC' },
  { value: 'base', label: 'Base' },
  { value: 'robinhood', label: 'Robinhood' },
  { value: 'other', label: 'Other' },
]

interface ChainFilterProps {
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}

export function ChainFilter({ value, onChange, disabled }: ChainFilterProps) {
  return (
    <label className="chain-filter">
      <span>Chain</span>
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
