<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 20 — the earliest CoinGecko historical market-cap point that cleared the
 * $5M threshold (as opposed to `peak_observed_at`, which is the point of the
 * MAXIMUM historical market cap). Feeds the `crossed_at` of a HISTORICAL_VERIFIED
 * qualification_events row. Nullable — legacy rows and any non-verified status
 * leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_peak_evidences', function (Blueprint $table): void {
            $table->timestamp('first_verified_crossing_at')->nullable()->after('peak_observed_at');
        });
    }

    public function down(): void
    {
        Schema::table('historical_peak_evidences', function (Blueprint $table): void {
            $table->dropColumn('first_verified_crossing_at');
        });
    }
};
