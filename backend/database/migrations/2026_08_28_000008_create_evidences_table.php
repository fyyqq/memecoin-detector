<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One evidence record = one timestamped FACT present around a PumpEvent.
     *
     * Step 16B stores evidence SEPARATELY from any interpretation. No causal
     * claims. Raw provider payloads are never stored — `raw_reference` is a
     * short id / domain / hash only.
     *
     * Duplicates for the same (event, source, url, time) are prevented by a
     * unique index on `(pump_event_id, dedupe_hash)`.
     */
    public function up(): void
    {
        Schema::create('evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // MARKET | TOKEN_METADATA | ORIGIN | NEWS | RELATED_TOKEN
            //   | LISTING | COMMUNITY   (last two reserved for future collectors)
            $table->string('category', 24);
            // internal | dexscreener | gdelt | rss:<host> | …
            $table->string('source', 64);
            $table->string('source_url', 1024)->nullable();
            $table->string('title', 512)->nullable();

            // observed_at  = when WE observed the fact (our data timestamp)
            // published_at = when the external source item was published
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // 0-100 — "how relevant to investigating this event", NOT a
            // probability that it caused the event.
            $table->unsignedTinyInteger('relevance_score')->default(0);
            $table->string('confidence', 8); // low | medium | high

            $table->text('summary');
            // Short provider id / domain / hash — never the full payload.
            $table->string('raw_reference', 255)->nullable();

            // sha1(category|source|source_url|title|published_at) — idempotency.
            $table->string('dedupe_hash', 40);
            // When this collection run last wrote / refreshed the row.
            $table->timestamp('collected_at');

            $table->timestamps();

            $table->unique(['pump_event_id', 'dedupe_hash']);
            $table->index(['pump_event_id', 'category']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidences');
    }
};
