<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 24 — Memecoin Risk & Safety Screening.
 *
 * One CURRENT risk assessment per token (unique on `token_id`, upserted and
 * re-evaluable). Sits on top of the existing market-cap qualification; it never
 * changes qualification, `observed_peak_market_cap`, pump events or evidence.
 *
 * `risk_level`  LOWER | MEDIUM | HIGH | CRITICAL | UNKNOWN
 *   - UNKNOWN is distinct from HIGH: it means "insufficient security data",
 *     never "high risk" and never "safe".
 * `risk_score`  deterministic 0-100, higher = more risk. NOT a probability of
 *   scam / rug / loss.
 * `screening_status`  completed | partial | failed
 * `data_completeness`  measured signals / applicable signals (0..1)
 *
 * No provider payloads are stored here — concise structured signals live in
 * `risk_signals`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // LOWER | MEDIUM | HIGH | CRITICAL | UNKNOWN
            $table->string('risk_level', 16);

            // Deterministic 0-100. Higher = more risk. Heuristic screening score.
            $table->unsignedTinyInteger('risk_score')->default(0);

            // measured signals / applicable signals, 0..1.
            $table->decimal('data_completeness', 4, 3)->default(0);

            // completed | partial | failed
            $table->string('screening_status', 16);

            // The single hard-override signal key that forced the level (e.g.
            // "is_honeypot", "is_mintable"), or null when the level came from
            // the score band. Never hidden from the UI.
            $table->string('hard_override_signal', 64)->nullable();

            // Whether this token PASSED the risk screen — level in {LOWER,
            // MEDIUM}, data completeness >= minimum, no hard filter tripped.
            // This deliberately EXCLUDES the live maturity gate (>= 72h), which
            // `GET /api/memecoins` applies in-query so it never goes stale.
            $table->boolean('main_list_eligible')->default(false);

            $table->timestamp('screened_at')->nullable();
            $table->string('provider_version', 64)->nullable();

            // Concise per-run notes (no stack traces, no payloads).
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique('token_id');
            $table->index('risk_level');
            $table->index('main_list_eligible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
