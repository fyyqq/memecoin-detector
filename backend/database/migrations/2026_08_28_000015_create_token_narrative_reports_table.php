<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 21 — Token Narrative Intelligence.
 *
 * One evidence-grounded report per token answering two SEPARATE token-level
 * questions:
 *
 *   origin      — why was this coin created?
 *   popularity  — why did this coin become popular?
 *
 * Both are AI syntheses of collected `token_narrative_sources` + our own stored
 * Evidence / market history. The AI never adds facts; every claim in the JSON
 * cites source ids. A section can be `completed` while the other is
 * `partial`/`failed` (the overall report is then `partial`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_narrative_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete()->unique();

            // pending | completed | partial | failed  (per section + overall)
            $table->string('origin_status', 16)->default('pending');
            $table->text('origin_summary')->nullable();
            $table->json('origin_explanation_json')->nullable();

            $table->string('popularity_status', 16)->default('pending');
            $table->text('popularity_summary')->nullable();
            $table->json('popularity_explanation_json')->nullable();

            // low | medium | high — the lower of the two section confidences.
            $table->string('overall_confidence', 16)->nullable();
            $table->string('overall_status', 16)->default('pending');

            $table->timestamp('research_started_at')->nullable();
            $table->timestamp('research_completed_at')->nullable();

            $table->string('model_provider', 32)->nullable();
            $table->string('model_name', 64)->nullable();
            // Which research-source providers actually contributed this run.
            $table->json('research_providers_used')->nullable();

            $table->timestamp('generated_at')->nullable();

            // Concise, non-sensitive. Provider stack traces are NEVER stored.
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('overall_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_narrative_reports');
    }
};
