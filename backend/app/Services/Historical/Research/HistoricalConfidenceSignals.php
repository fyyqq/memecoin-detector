<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * The deterministic inputs to {@see HistoricalConfidenceCalculator}.
 *
 * Every field is an evidence characteristic — nothing here is a subjective
 * score. Cross-source reconciliation is a later phase; `corroboratingSources`
 * is accepted now (default 0) so that phase needs no signature change.
 */
final readonly class HistoricalConfidenceSignals
{
    public function __construct(
        /** The metric was actually obtained (false ⇒ always Unknown). */
        public bool $metricAvailable,
        public SourceCredibility $sourceCredibility,
        public MetricBasis $basis,
        /** A real `observed_at` timestamp is attached to the figure. */
        public bool $hasObservedTimestamp,
        /** Token identity was resolved to `chain_id` + `token_address`. */
        public bool $identityVerified,
        /** Independent sources that agree on this figure (reconciliation phase). */
        public int $corroboratingSources = 0,
    ) {}
}
