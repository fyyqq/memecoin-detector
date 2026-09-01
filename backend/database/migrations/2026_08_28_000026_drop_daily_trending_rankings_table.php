<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "Yesterday's Trending" archive along with the rest of the
 * near-real-time Trending Tracking feature. Read via the removed
 * `GET /api/memecoins/trending/history` endpoint only.
 *
 * `down()` recreates the table exactly as migration 000022 left it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('daily_trending_rankings');
    }

    public function down(): void
    {
        Schema::create('daily_trending_rankings', function (Blueprint $table): void {
            $table->id();

            $table->date('date');
            $table->string('chain_bucket', 16);
            $table->string('timeframe', 8);

            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chain_id', 64);
            $table->string('token_address', 128);
            $table->string('symbol', 64)->nullable();
            $table->string('name', 191)->nullable();

            $table->unsignedInteger('best_rank');
            $table->double('best_score');

            $table->double('peak_market_cap')->nullable();
            $table->double('peak_volume')->nullable();
            $table->double('peak_liquidity')->nullable();

            $table->unsignedInteger('appearances')->default(1);

            $table->string('trending_meta_slug', 128)->nullable();
            $table->string('trending_meta_name', 191)->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->unique(
                ['date', 'chain_bucket', 'timeframe', 'token_address'],
                'daily_trending_rankings_unique',
            );
            $table->index(['date', 'chain_bucket', 'timeframe', 'best_rank'], 'daily_trending_rankings_lookup_idx');
        });
    }
};
