<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 22 (corrected) — "Monthly Top Memecoins".
 *
 * For EVERY calendar month, the top-1 performing memecoin inside each of FIVE
 * fixed display buckets: solana, robinhood, bsc, base, other. So the unique key
 * is `(year, month, chain_bucket)` — at most 12 × 5 = 60 rows a year. There is
 * NO global monthly winner and NO unlimited per-chain rows.
 *
 * The champion of a bucket is the single memecoin with the strongest SUPPORTED
 * performance in the eligible universe for that month + bucket, scored primarily
 * on observed market-cap growth (baseline -> peak within the month). It is NOT
 * the biggest token, highest market cap, most liquidity, or first to cross $5M.
 * Risk score and AI are NEVER used for selection.
 *
 *   provisional              — the current (in-progress) month; recomputed daily.
 *   finalized                — a completed past month with sufficient internal
 *                              evidence; immutable during normal operation.
 *   best_supported_candidate — a completed past month where a real token led the
 *                              bucket but the evidence is incomplete (thin
 *                              observation coverage, or only web research).
 *   no_verified_champion     — a completed past month with no defensible winner.
 *   future                   — a month that has not happened yet; token = null.
 *
 * Historical provenance: `source_type` (internal_observed / dexscreener /
 * web_research / other_verified_source), `source_reference`, `confidence`
 * (high / medium / low). We never claim an exact DexScreener historical rank
 * unless a source actually establishes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_rankings', function (Blueprint $table): void {
            $table->id();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1..12

            // solana | robinhood | bsc | base | other
            $table->string('chain_bucket', 16);

            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();

            // provisional | finalized | best_supported_candidate |
            // no_verified_champion | future
            $table->string('status', 32)->default('provisional');

            // Transparent 0..100 performance score. NOT a prediction of returns.
            $table->double('performance_score')->nullable();

            // All from OBSERVED / VERIFIED market cap in the month — never FDV,
            // never a historical estimate.
            $table->double('baseline_market_cap')->nullable();
            $table->double('peak_market_cap')->nullable();
            $table->double('market_cap_growth_pct')->nullable();
            $table->double('peak_expansion_ratio')->nullable();

            // Supporting evidence only (volume / liquidity / txns / price change).
            $table->double('activity_score')->nullable();

            $table->unsignedInteger('observation_count')->nullable();
            $table->double('observation_coverage_ratio')->nullable();

            // Small audit trail — how the score was reached (weights, references,
            // the runner-up, candidate counts). Counts only, no snapshots.
            $table->json('scoring_breakdown')->nullable();

            // Historical provenance.
            $table->string('source_type', 32)->nullable();   // internal_observed | dexscreener | web_research | other_verified_source
            $table->string('source_reference', 500)->nullable();
            $table->string('confidence', 12)->nullable();     // high | medium | low

            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            // AT MOST one champion per month per bucket.
            $table->unique(['year', 'month', 'chain_bucket']);
            $table->index(['year', 'chain_bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_rankings');
    }
};
