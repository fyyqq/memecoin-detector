<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trending Now correction — `trending_snapshots` now only stores tokens that
 * passed the Trending-Now eligibility filter (memecoin + age ≤ 30d + CURRENT
 * market cap in [$5M, $200M] + volume > 0 + liquidity > 0). `is_memecoin_candidate`
 * records the classifier verdict for transparency.
 *
 *   TRUE     — a clear memecoin (meme narrative meta and/or meme name/symbol)
 *   UNKNOWN  — ambiguous (kept out of Trending Now unless meme signals are strong)
 *   FALSE    — a clear non-memecoin (stablecoin / wrapped / infra / blue-chip) —
 *              never stored here
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trending_snapshots', function (Blueprint $table): void {
            $table->string('is_memecoin_candidate', 8)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('trending_snapshots', function (Blueprint $table): void {
            $table->dropColumn('is_memecoin_candidate');
        });
    }
};
