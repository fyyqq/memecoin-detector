<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 24 — the structured signals behind a {@see risk_assessments} row.
 *
 * One row per signal per assessment. The full set is REPLACED on every rescan
 * so `risk_assessment_id` + `signal_key` is effectively the current set.
 *
 * TRI-STATE `state`:
 *   MEASURED       a real value was read
 *   BAD            a measured value that is dangerous / a hard flag
 *   UNKNOWN        null / "" / missing / unsupported chain — contributes 0
 *   NOT_AVAILABLE  can never be obtained from a free official API (top traders)
 *
 * `explanation` is a short, pre-written label (NOT LLM-generated) — a presenter
 * turns the structured row into UI copy. No provider payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_signals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // e.g. "is_mintable", "sell_tax", "top1_effective_pct".
            $table->string('signal_key', 64);
            // contract_security | exit_safety | holder_distribution | liquidity |
            // pump_dump | market_structure | age
            $table->string('signal_group', 32);

            // MEASURED | BAD | UNKNOWN | NOT_AVAILABLE
            $table->string('state', 16);

            // Compact display value ("true", "0.05", "42.1%", "—"). No payloads.
            $table->string('value', 120)->nullable();
            // Machine value for deterministic scoring, when numeric.
            $table->double('numeric_value')->nullable();
            $table->string('unit', 24)->nullable();

            // none | low | medium | high | critical — the signal's own severity.
            $table->string('severity', 16)->default('none');

            // goplus | geckoterminal | dexscreener | internal
            $table->string('source', 24)->nullable();
            $table->timestamp('source_checked_at')->nullable();

            // Pre-written concise explanation (never dynamically generated text).
            $table->string('explanation', 300)->nullable();

            $table->timestamps();

            $table->unique(['risk_assessment_id', 'signal_key']);
            $table->index(['token_id', 'signal_group']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signals');
    }
};
