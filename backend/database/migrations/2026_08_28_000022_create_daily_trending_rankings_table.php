<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily trending archive — one row per token per day per chain bucket per
 * timeframe, enough to reconstruct "what was trending yesterday" (Top 10 / Top
 * 20) without keeping every 5-minute snapshot forever.
 *
 * `collect-trending` UPSERTs today's row on each run:
 *   best_rank        = MIN(rank seen today)
 *   best_score       = MAX(score seen today)
 *   peak_market_cap / peak_volume / peak_liquidity = MAX seen today
 *   appearances     += 1
 *   first_seen_at    = preserved
 *   last_seen_at     = now
 *
 * `chain_bucket` is one of the FIVE display buckets (solana/robinhood/bsc/base/
 * other) — the token keeps its real `chain_id` in the `chain_id` column; only
 * this column ever says "other".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_trending_rankings', function (Blueprint $table): void {
            $table->id();

            $table->date('date');
            // solana | robinhood | bsc | base | other
            $table->string('chain_bucket', 16);
            // 6h | 24h
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

    public function down(): void
    {
        Schema::dropIfExists('daily_trending_rankings');
    }
};
