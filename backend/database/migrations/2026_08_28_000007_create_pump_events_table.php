<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A detected significant upward move in a token's OBSERVATION SERIES
     * (our ~10-minute snapshots) — an "observed pump", not a tick-level market
     * event. One row per meaningful movement; overlapping detections are merged.
     *
     * Step 16A is the EVENT ONLY. No evidence / catalyst records yet (16B/16C).
     */
    public function up(): void
    {
        Schema::create('pump_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // Observation timestamps — never claimed to be exact tick boundaries.
            $table->timestamp('started_at');   // first observation of the upward move
            $table->timestamp('peak_at');      // observation with the highest market cap
            $table->timestamp('ended_at')->nullable();  // null while status = active

            $table->double('start_market_cap')->nullable();
            $table->double('peak_market_cap')->nullable();
            $table->double('start_price_usd')->nullable();
            $table->double('peak_price_usd')->nullable();

            $table->double('market_cap_change_pct')->nullable();
            $table->double('price_change_pct')->nullable();

            // ROLLING 24h ratios — latest.volume_h24 / start.volume_h24 etc.
            // NOT interval volume/transaction counts. See config/pump.php.
            $table->double('volume_h24_change_ratio')->nullable();
            $table->double('txns_h24_change_ratio')->nullable();

            $table->unsignedInteger('duration_minutes')->nullable();

            // Deterministic 0-100 strength score. NOT a probability / prediction.
            $table->unsignedTinyInteger('detection_score')->default(0);
            $table->string('confidence', 8);   // low | medium | high
            $table->string('status', 12);      // active | completed

            $table->timestamps();

            $table->index(['token_id', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_events');
    }
};
