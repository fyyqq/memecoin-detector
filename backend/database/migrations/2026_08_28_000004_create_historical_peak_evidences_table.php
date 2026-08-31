<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_peak_evidences', function (Blueprint $table) {
            $table->id();

            // One current evidence row per token (upserted, re-evaluable).
            $table->foreignId('token_id')->constrained()->cascadeOnDelete()->unique();

            // CURRENT_OBSERVATION | HISTORICAL_VERIFIED | HISTORICAL_ESTIMATE | UNKNOWN
            $table->string('status', 32);

            // The peak the status is based on. NULL for UNKNOWN.
            $table->double('peak_value_usd')->nullable();
            $table->timestamp('peak_observed_at')->nullable();

            // dexscreener | coingecko | geckoterminal
            $table->string('evidence_source', 32)->nullable();
            // market_cap | fdv_total_supply | current_market_cap
            $table->string('evidence_basis', 32)->nullable();

            // Short pointer to the source (coingecko coin id, GT pool address,
            // "market_snapshots"). NOT a dump of the provider payload.
            $table->string('source_reference', 255)->nullable();

            // The historical window actually inspected (never claims data we do
            // not have).
            $table->timestamp('historical_window_start')->nullable();
            $table->timestamp('historical_window_end')->nullable();

            // high | medium | low
            $table->string('confidence', 16)->nullable();

            // When the external historical lookup last ran for this token. Drives
            // the re-lookup cooldown.
            $table->timestamp('checked_at')->nullable();

            // One short human line, e.g. "coingecko market_caps all zero;
            // fell back to geckoterminal". No JSON.
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_peak_evidences');
    }
};
