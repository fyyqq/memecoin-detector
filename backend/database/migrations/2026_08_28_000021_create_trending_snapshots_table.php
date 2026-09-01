<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Near-real-time Trending Tracking — one row per (chain, token, timeframe) per
 * 5-minute capture bucket.
 *
 * CRITICAL: this table is HISTORY. A token that was trending yesterday keeps
 * every snapshot it ever had, even after it drops out of trending. The
 * `memecoins:collect-trending` command UPSERTs on
 * `(chain_id, token_address, timeframe, capture_bucket)` so re-running inside one
 * bucket refreshes the row rather than appending; a new bucket appends.
 *
 * `tracked_trend_score` is our transparent deterministic INTERNAL score — it is
 * NOT DexScreener's proprietary `trendingScoreH6/H24`. `source` is always
 * `dexscreener_meta`. See docs/trending-tracking.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trending_snapshots', function (Blueprint $table): void {
            $table->id();

            // Linked when we track the token; null for a brand-new trending
            // token before its first enrichment.
            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();

            $table->string('chain_id', 64);
            $table->string('token_address', 128);
            $table->string('pair_address', 128)->nullable();
            $table->string('dex_id', 64)->nullable();
            $table->string('symbol', 64)->nullable();
            $table->string('name', 191)->nullable();

            // 6h | 24h
            $table->string('timeframe', 8);

            // epoch seconds floored to the refresh interval — the dedupe key.
            $table->unsignedBigInteger('capture_bucket');

            $table->unsignedInteger('trend_rank');
            $table->double('tracked_trend_score');
            // { momentum, volume_activity, transaction_activity, liquidity_quality, persistence }
            $table->json('trend_score_components')->nullable();
            // How many of the recent captures this token was trending (persistence input).
            $table->unsignedInteger('trend_appearances')->default(1);

            // Market data from the representative pair at capture time.
            $table->double('market_cap')->nullable();
            $table->double('liquidity_usd')->nullable();
            $table->double('volume_usd')->nullable();
            $table->double('price_change_pct')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->timestamp('pair_created_at')->nullable();

            $table->string('trending_meta_slug', 128)->nullable();
            $table->string('trending_meta_name', 191)->nullable();

            // Provenance — never "DexScreener Trending Score".
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

    public function down(): void
    {
        Schema::dropIfExists('trending_snapshots');
    }
};
