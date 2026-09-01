<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the near-real-time Trending Tracking feature. `trending_snapshots`
 * powered the "Top Trending Memecoins" homepage section (`GET /api/memecoins/trending`)
 * and fed the discovery prioritizer a trend-rank signal — both of which were
 * removed. The surviving "Chain Market Activity" / "Top Volume by Chain" views
 * read `tokens` + `market_snapshots`, not this table.
 *
 * `down()` recreates the table exactly as migrations 000021 + 000024 left it, so
 * the removal is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('trending_snapshots');
    }

    public function down(): void
    {
        Schema::create('trending_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();

            $table->string('chain_id', 64);
            $table->string('token_address', 128);
            $table->string('pair_address', 128)->nullable();
            $table->string('dex_id', 64)->nullable();
            $table->string('symbol', 64)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('is_memecoin_candidate', 8)->nullable()->after('name');

            $table->string('timeframe', 8);
            $table->unsignedBigInteger('capture_bucket');

            $table->unsignedInteger('trend_rank');
            $table->double('tracked_trend_score');
            $table->json('trend_score_components')->nullable();
            $table->unsignedInteger('trend_appearances')->default(1);

            $table->double('market_cap')->nullable();
            $table->double('liquidity_usd')->nullable();
            $table->double('volume_usd')->nullable();
            $table->double('price_change_pct')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->timestamp('pair_created_at')->nullable();

            $table->string('trending_meta_slug', 128)->nullable();
            $table->string('trending_meta_name', 191)->nullable();

            $table->string('source', 32)->default('dexscreener_meta');

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['chain_id', 'token_address', 'timeframe', 'capture_bucket'],
                'trending_snapshots_capture_unique',
            );
            $table->index(['chain_id', 'token_address', 'timeframe', 'captured_at'], 'trending_snapshots_token_tf_time_idx');
            $table->index(['timeframe', 'captured_at'], 'trending_snapshots_tf_time_idx');
            $table->index(['token_address', 'captured_at'], 'trending_snapshots_addr_time_idx');
            $table->index(['timeframe', 'capture_bucket', 'trend_rank'], 'trending_snapshots_tf_bucket_rank_idx');
        });
    }
};
