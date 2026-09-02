<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * How a {@see HistoricalMetricResult} value was arrived at — the honesty layer.
 *
 *   observed      — the source directly reports this figure for the month
 *                   (e.g. CoinGecko `market_caps[]` point, a dated explorer
 *                   snapshot of `holders.count`).
 *   reconstructed — aggregated / derived from lower-level source data that IS
 *                   directly observed (e.g. summing daily `total_volumes[]`
 *                   into a monthly volume). Still evidence, but one step removed.
 *   estimate      — a modelled figure that depends on an assumption
 *                   (e.g. price × supply). NEVER usable as a verified market cap.
 *   none          — the metric is unavailable; there is no value.
 *
 * The word "verified" is deliberately NOT a basis here — a metric is only ever
 * `observed`, `reconstructed`, `estimate`, or `none`.
 */
enum MetricBasis: string
{
    case Observed = 'observed';
    case Reconstructed = 'reconstructed';
    case Estimate = 'estimate';
    case None = 'none';

    /** An estimate must never be treated as an observed/verified figure. */
    public function isEstimate(): bool
    {
        return $this === self::Estimate;
    }

    public function isObserved(): bool
    {
        return $this === self::Observed;
    }
}
