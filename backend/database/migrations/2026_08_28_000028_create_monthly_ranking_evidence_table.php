<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 26 (Phase 1) — `monthly_ranking_evidence`.
 *
 * A CHILD evidence table hanging off `monthly_rankings` — NOT another ranking
 * table. One row = one historical metric obtained from one source for one
 * ranked entry:
 *
 *   metric        holders | volume | market_cap | ohlcv | identity | pool_date
 *   basis         observed | reconstructed | estimate     (never "verified";
 *                 an estimate is always labelled an estimate)
 *   confidence    high | medium | low | unknown            (DERIVED deterministically
 *                 from evidence characteristics — never hand-typed)
 *
 * Per-metric provenance lives here so a bucket's ranking can be explained
 * source-by-source ("holder count 45,000 — Etherscan snapshot 2026-01-31,
 * observed; monthly volume $180M — CoinGecko total_volumes sum, reconstructed").
 * The loose `monthly_rankings.source_evidence` JSON blob stays for backward
 * compatibility but structured evidence is recorded here.
 *
 * Not every metric must exist for a row — a missing metric is simply an absent
 * evidence record (the score renormalizes, per Step 25). Fake values are never
 * stored.
 *
 * Duplicate evidence for the same (monthly_ranking, metric, source) is prevented
 * by a unique index on `(monthly_ranking_id, dedupe_hash)`, where `dedupe_hash`
 * is a deterministic digest of `metric` + normalized `source_name` + source URL
 * host — so re-running research is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_ranking_evidence', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('monthly_ranking_id')
                ->constrained('monthly_rankings')
                ->cascadeOnDelete();

            // holders | volume | market_cap | ohlcv | identity | pool_date
            $table->string('metric', 24);

            $table->string('source_name', 160);
            $table->string('source_url', 1024)->nullable();

            // The scalar figure for a scoring metric (holders / volume /
            // market_cap). Null for identity / ohlcv / pool_date and for an
            // unavailable metric — a missing metric is an absent row, not a 0.
            $table->double('value_numeric')->nullable();

            // When the figure was observed (the source's data timestamp).
            $table->timestamp('observed_at')->nullable();

            // Short prose: how the figure was obtained.
            $table->string('methodology', 500)->nullable();

            // observed | reconstructed | estimate
            $table->string('basis', 16);

            // high | medium | low | unknown — deterministic, never arbitrary.
            $table->string('confidence', 8);

            // Known caveats (partial month coverage, un-listed token, …).
            $table->string('limitations', 500)->nullable();

            // Structured detail for non-scalar metrics (identity fields, OHLCV
            // candle summary). Never a scraped page body.
            $table->json('metadata')->nullable();

            // Deterministic digest of metric + normalized source — idempotent
            // re-research.
            $table->string('dedupe_hash', 64);

            $table->timestamps();

            $table->unique(['monthly_ranking_id', 'dedupe_hash'], 'mre_ranking_dedupe_unique');
            $table->index(['monthly_ranking_id', 'metric'], 'mre_ranking_metric_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_ranking_evidence');
    }
};
