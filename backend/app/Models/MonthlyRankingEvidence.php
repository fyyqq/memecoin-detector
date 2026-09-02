<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Historical\Research\HistoricalMetric;
use App\Services\Historical\Research\MetricBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One historical metric obtained from one source for one ranked
 * {@see MonthlyRanking} entry (Step 26, Phase 1).
 *
 * This is a CHILD evidence table — it never changes a ranking. It records
 * per-metric provenance so a bucket's Top 3 can be explained source-by-source
 * ("holder count 45,000 — Etherscan snapshot, observed; monthly volume $180M —
 * CoinGecko total_volumes sum, reconstructed").
 *
 *   `basis`      observed | reconstructed | estimate  — an estimate is ALWAYS
 *                labelled an estimate, never a verified market cap.
 *   `confidence` high | medium | low | unknown        — DERIVED deterministically
 *                by App\Services\Historical\Research\HistoricalConfidenceCalculator,
 *                never hand-typed.
 *
 * A missing metric is an ABSENT row (the participation score renormalizes) —
 * fake values are never stored. Idempotent re-research is enforced by the unique
 * index on `(monthly_ranking_id, dedupe_hash)`.
 */
class MonthlyRankingEvidence extends Model
{
    /** Laravel would pluralize to "monthly_ranking_evidences". */
    protected $table = 'monthly_ranking_evidence';

    protected $fillable = [
        'monthly_ranking_id',
        'metric',
        'source_name',
        'source_url',
        'value_numeric',
        'observed_at',
        'methodology',
        'basis',
        'confidence',
        'limitations',
        'metadata',
        'dedupe_hash',
    ];

    protected function casts(): array
    {
        return [
            'value_numeric' => 'float',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<MonthlyRanking, $this> */
    public function monthlyRanking(): BelongsTo
    {
        return $this->belongsTo(MonthlyRanking::class);
    }

    public function metricEnum(): HistoricalMetric
    {
        return HistoricalMetric::from((string) $this->metric);
    }

    public function basisEnum(): MetricBasis
    {
        return MetricBasis::from((string) $this->basis);
    }

    public function isEstimate(): bool
    {
        return $this->basis === MetricBasis::Estimate->value;
    }

    /**
     * Deterministic digest of `metric` + normalized `source_name` + source URL
     * host — the idempotency key for `(monthly_ranking_id, dedupe_hash)`. Two
     * research runs that find the same figure from the same source upsert one
     * row.
     */
    public static function dedupeHash(string $metric, string $sourceName, ?string $sourceUrl): string
    {
        $host = '';
        if ($sourceUrl !== null && $sourceUrl !== '') {
            $host = mb_strtolower((string) (parse_url($sourceUrl, PHP_URL_HOST) ?: ''));
        }

        $normalizedName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $sourceName) ?? $sourceName));

        return hash('sha256', $metric.'|'.$normalizedName.'|'.$host);
    }
}
