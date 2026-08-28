interface MarketCapSparklineProps {
  /** Market caps in chronological order (oldest → newest). Nulls are skipped. */
  values: Array<number | null>
}

const WIDTH = 640
const HEIGHT = 120

/**
 * Dependency-free market-cap-over-time line. Not a full chart — a lightweight
 * visual companion to the observation table. Renders nothing with < 2 points.
 */
export function MarketCapSparkline({ values }: MarketCapSparklineProps) {
  const points = values.filter((value): value is number => value !== null && Number.isFinite(value))
  if (points.length < 2) return null

  const min = Math.min(...points)
  const max = Math.max(...points)
  const span = max - min || 1
  const step = WIDTH / (points.length - 1)

  const path = points
    .map((value, index) => {
      const x = index * step
      const y = HEIGHT - ((value - min) / span) * HEIGHT
      return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')

  return (
    <svg
      className="sparkline"
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      preserveAspectRatio="none"
      role="img"
      aria-label="Market cap across recent observations (oldest to newest)"
    >
      <path
        d={path}
        fill="none"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  )
}
