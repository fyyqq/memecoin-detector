<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 20 — "Recently Crossed $5M".
 *
 * One row per token per crossing TYPE. Records the earliest observation at which
 * a VERIFIED / OBSERVED market cap cleared the $5M threshold:
 *
 *   CURRENT_OBSERVATION  our own MarketSnapshot market_cap >= $5M
 *   HISTORICAL_VERIFIED  CoinGecko verified historical market cap >= $5M
 *
 * HISTORICAL_ESTIMATE (FDV basis) NEVER produces a crossing event.
 *
 * A token may hold BOTH a CURRENT_OBSERVATION and a HISTORICAL_VERIFIED row.
 * Precedence for the "representative" crossing is HISTORICAL_VERIFIED >
 * CURRENT_OBSERVATION; the other row is preserved for the record.
 *
 * Events are created only during the ingestion / qualification pipeline — never
 * by a read API. The `(token_id, type)` unique constraint makes repeated
 * scheduler runs idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // CURRENT_OBSERVATION | HISTORICAL_VERIFIED
            $table->string('type', 32);

            // Earliest observation at which the threshold was cleared. For a
            // sampled/candled external provider this is the earliest verified
            // >= $5M point, NOT a claim of the exact tick-level crossing time.
            $table->timestamp('crossed_at');

            // The threshold in force when the crossing was recorded (usually $5M).
            $table->unsignedBigInteger('threshold_usd');

            // The HistoricalPeakEvidence status at creation. Mirrors `type`, kept
            // as a separate column so the provenance is explicit.
            $table->string('evidence_status', 32);

            // dexscreener (CURRENT_OBSERVATION) | coingecko (HISTORICAL_VERIFIED)
            $table->string('source', 32)->nullable();

            // Market cap value at the crossing point (nullable — a candled
            // provider may not expose an exact figure).
            $table->double('market_cap_value')->nullable();

            $table->timestamps();

            // One crossing per type per token → idempotent re-runs.
            $table->unique(['token_id', 'type']);
            // The recently-crossed window query orders / filters on this.
            $table->index('crossed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_events');
    }
};
