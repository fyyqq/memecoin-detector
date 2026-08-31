<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 21 — one concise source record behind a narrative report.
 *
 * NOT a giant JSON blob: each web page / internal fact the synthesis rests on is
 * its own row with source metadata + a one-sentence claim summary. The narrative
 * JSON references these rows by `id` (`source_ids`). We store metadata + a claim
 * only — we never scrape or store full HTML page bodies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_narrative_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('token_narrative_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // origin | popularity
            $table->string('section', 16);
            // official | news | social | market | community | reference
            $table->string('source_type', 16);

            $table->string('source_name', 120);
            $table->string('source_url', 1024)->nullable();
            $table->string('title', 500)->nullable();

            // Real publication date, or NULL — never fabricated.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('accessed_at');

            // Concise one-sentence factual claim this source supports.
            $table->text('claim');

            $table->unsignedTinyInteger('relevance_score')->default(0);
            // low | medium | high — source quality tier.
            $table->string('confidence', 16)->default('low');

            // Which research provider produced this (internal | gdelt | …).
            $table->string('provider', 32)->default('internal');
            // sha1 of the identifying fields — idempotent re-research upserts.
            $table->string('dedupe_hash', 40);

            $table->timestamps();

            $table->unique(['token_narrative_report_id', 'dedupe_hash']);
            $table->index(['token_narrative_report_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_narrative_sources');
    }
};
