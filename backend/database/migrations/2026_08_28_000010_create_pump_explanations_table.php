<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI-generated, evidence-grounded interpretation per PumpEvent
     * (Step 16C). The model is an interpreter of stored Evidence — it never adds
     * facts. Regeneratable: evidence changes over time, so `generated_at` is
     * tracked and the row is upserted, never frozen.
     *
     * `explanation_json` holds the full validated structured result
     * (summary / primary_catalyst / secondary_signals / evidence / confidence /
     * caveats / unknowns). The scalar columns are denormalised headline copies
     * for cheap listing / filtering.
     */
    public function up(): void
    {
        Schema::create('pump_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_event_id')->unique()->constrained()->cascadeOnDelete();

            // pending | completed | failed
            $table->string('status', 12)->default('pending');

            $table->text('summary')->nullable();
            // One of the fixed catalyst categories, or UNKNOWN. Never free text.
            $table->string('primary_catalyst', 32)->nullable();
            // high | medium | low
            $table->string('confidence', 8)->nullable();

            // The complete validated structured explanation.
            $table->jsonb('explanation_json')->nullable();
            // How many evidence records were sent to the model for this run.
            $table->unsignedSmallInteger('evidence_count')->default(0);

            $table->string('model_provider', 32)->nullable();
            $table->string('model_name', 64)->nullable();

            // Concise reason when status = failed (provider error / invalid output).
            $table->text('error_message')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('primary_catalyst');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_explanations');
    }
};
