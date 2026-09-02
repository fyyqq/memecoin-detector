<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

use Carbon\CarbonImmutable;

/**
 * The strongly-typed result of asking a {@see HistoricalResearchProvider} for
 * one historical metric (Step 26, Phase 1).
 *
 * An UNAVAILABLE metric is a first-class value: `available === false`,
 * `value === null`, `basis === MetricBasis::None`, `confidence ===
 * HistoricalConfidence::Unknown`. It NEVER pushes a fabricated number into the
 * scoring system — callers check `available` / `scalarValue()`.
 *
 * `basis` records HOW the figure was obtained — `observed` / `reconstructed` /
 * `estimate` / `none`. There is deliberately no "verified" basis: an estimate
 * is always labelled an estimate.
 *
 * This DTO does not persist itself. {@see toEvidenceAttributes()} maps it to the
 * `monthly_ranking_evidence` columns for a later phase to write.
 */
final readonly class HistoricalMetricResult
{
    /**
     * @param  array<string,mixed>  $metadata  structured detail for non-scalar
     *                                         metrics (identity fields, OHLCV
     *                                         candle summary, …) — never a
     *                                         scraped page body
     */
    private function __construct(
        public HistoricalMetric $metric,
        public bool $available,
        public ?float $value,
        public ?string $sourceName,
        public ?string $sourceUrl,
        public ?CarbonImmutable $observedAt,
        public ?string $methodology,
        public MetricBasis $basis,
        public HistoricalConfidence $confidence,
        public ?string $limitations,
        public array $metadata = [],
    ) {}

    /**
     * The metric could not be obtained. No value enters the scoring system.
     */
    public static function unavailable(HistoricalMetric $metric, ?string $reason = null): self
    {
        return new self(
            metric: $metric,
            available: false,
            value: null,
            sourceName: null,
            sourceUrl: null,
            observedAt: null,
            methodology: null,
            basis: MetricBasis::None,
            confidence: HistoricalConfidence::Unknown,
            limitations: $reason,
            metadata: [],
        );
    }

    /**
     * A resolved metric. `confidence` is DERIVED here from
     * {@see HistoricalConfidenceCalculator} — callers pass evidence
     * characteristics, never a band directly.
     *
     * @param  array<string,mixed>  $metadata
     */
    public static function resolved(
        HistoricalMetric $metric,
        ?float $value,
        string $sourceName,
        ?string $sourceUrl,
        ?CarbonImmutable $observedAt,
        string $methodology,
        MetricBasis $basis,
        SourceCredibility $sourceCredibility,
        bool $identityVerified,
        ?string $limitations = null,
        int $corroboratingSources = 0,
        array $metadata = [],
    ): self {
        $confidence = (new HistoricalConfidenceCalculator)->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: true,
            sourceCredibility: $sourceCredibility,
            basis: $basis,
            hasObservedTimestamp: $observedAt !== null,
            identityVerified: $identityVerified,
            corroboratingSources: $corroboratingSources,
        ));

        return new self(
            metric: $metric,
            available: true,
            value: $value,
            sourceName: $sourceName,
            sourceUrl: $sourceUrl,
            observedAt: $observedAt,
            methodology: $methodology,
            basis: $basis,
            confidence: $confidence,
            limitations: $limitations,
            metadata: $metadata,
        );
    }

    /** The scalar figure for a scoring metric, or null when unavailable / non-scalar. */
    public function scalarValue(): ?float
    {
        return $this->available && $this->metric->isScalar() ? $this->value : null;
    }

    public function isEstimate(): bool
    {
        return $this->basis->isEstimate();
    }

    public function isObserved(): bool
    {
        return $this->basis->isObserved();
    }

    /**
     * Column values for a `monthly_ranking_evidence` row (minus
     * `monthly_ranking_id`). Not persisted here — a later phase writes it.
     *
     * @return array<string,mixed>
     */
    public function toEvidenceAttributes(): array
    {
        return [
            'metric' => $this->metric->value,
            'source_name' => $this->sourceName,
            'source_url' => $this->sourceUrl,
            'value_numeric' => $this->metric->isScalar() ? $this->value : null,
            'observed_at' => $this->observedAt,
            'methodology' => $this->methodology,
            'basis' => $this->basis->value,
            'confidence' => $this->confidence->value,
            'limitations' => $this->limitations,
            'metadata' => $this->metadata !== [] ? $this->metadata : null,
        ];
    }
}
