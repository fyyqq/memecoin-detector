<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Chain Market Activity" — one materialised row per chain bucket per day,
 * upserted on every `collect-trending` run from `tokens` + each token's latest
 * `market_snapshot` (deduplicated token-level representative-pair volume, behind
 * the market-integrity gate).
 *
 * Aggregation rule (documented in docs/trending-tracking.md): sum ONE volume /
 * liquidity figure per tracked token — its latest snapshot's representative-pair
 * `volume_h24` / `liquidity_usd` — never every DexScreener pair, so a token with
 * many pools is never double-counted.
 *
 * `total_volume_usd` is REPORTED volume — it is NOT claimed to be organic / real
 * human volume. The day-over-day delta in the read API compares today's row to
 * yesterday's row (null when there is no prior row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_chain_activity', function (Blueprint $table): void {
            $table->id();

            $table->date('date');
            // solana | robinhood | bsc | base | other
            $table->string('chain_bucket', 16);

            $table->double('total_volume_usd')->default(0);
            $table->double('total_liquidity_usd')->default(0);
            $table->unsignedInteger('active_token_count')->default(0);

            $table->foreignId('top_token_id')->nullable()->constrained('tokens')->nullOnDelete();
            $table->string('top_token_address', 128)->nullable();
            $table->string('top_token_symbol', 64)->nullable();
            $table->double('top_token_volume')->nullable();

            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['date', 'chain_bucket'], 'daily_chain_activity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_chain_activity');
    }
};
