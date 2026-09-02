<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes "Chain Market Activity" along with the "Top Volume by Chain" and
 * "Main Memecoin List" homepage sections in the dashboard-simplification pass.
 *
 * `daily_chain_activity` was materialised by `ChainActivityRollup` inside
 * `memecoins:discover` and read ONLY by the removed
 * `GET /api/memecoins/chain-activity` endpoint. The rollup, the endpoint, the
 * `DailyChainActivity` model and `App\Services\Trending\*` are all deleted.
 *
 * `down()` recreates the table exactly as migration 000023 left it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('daily_chain_activity');
    }

    public function down(): void
    {
        Schema::create('daily_chain_activity', function (Blueprint $table): void {
            $table->id();

            $table->date('date');
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
};
