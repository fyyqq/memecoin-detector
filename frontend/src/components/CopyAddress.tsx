import { type MouseEvent as ReactMouseEvent, useEffect, useRef, useState } from 'react'

type CopyState = 'idle' | 'copied' | 'error'

interface CopyAddressProps {
  /** The exact value copied to the clipboard — always the full token_address. */
  address: string
  className?: string
}

const RESET_MS = 1_600

/**
 * Compact "copy contract address" control. Copies the exact `address` (never a
 * symbol, name, or pair address). Stops click/keydown propagation so it can sit
 * inside a clickable table row without triggering navigation.
 */
export function CopyAddress({ address, className }: CopyAddressProps) {
  const [state, setState] = useState<CopyState>('idle')
  const timer = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearTimeout(timer.current), [])

  const handleClick = async (event: ReactMouseEvent<HTMLButtonElement>) => {
    event.stopPropagation()
    event.preventDefault()

    const ok = await copyText(address)
    setState(ok ? 'copied' : 'error')
    window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => setState('idle'), RESET_MS)
  }

  const tooltip =
    state === 'copied' ? 'Copied' : state === 'error' ? 'Copy failed' : 'Copy contract address'
  const glyph = state === 'copied' ? '✓' : state === 'error' ? '✕' : '⧉'
  const text = state === 'copied' ? 'Copied' : state === 'error' ? 'Failed' : 'Copy'

  return (
    <button
      type="button"
      className={`copy-btn${className ? ` ${className}` : ''}${state !== 'idle' ? ` copy-btn-${state}` : ''}`}
      title={tooltip}
      aria-label={tooltip}
      onClick={(event) => void handleClick(event)}
      onKeyDown={(event) => event.stopPropagation()}
    >
      <span aria-hidden="true">{glyph}</span>
      <span className="copy-btn-text">{text}</span>
    </button>
  )
}

/** Clipboard API with a legacy fallback and a safe failure path. */
async function copyText(text: string): Promise<boolean> {
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // fall through to the legacy path
  }

  try {
    const area = document.createElement('textarea')
    area.value = text
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.top = '-1000px'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(area)
    return ok
  } catch {
    return false
  }
}
