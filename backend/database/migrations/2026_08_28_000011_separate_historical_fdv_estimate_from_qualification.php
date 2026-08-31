<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-rule correction: an FDV-basis historical ESTIMATE
     * (`peak price × total supply`) must NEVER sit in the verified/observed
     * market-cap qualification column, and must NOT qualify a token for the main
     * ≥ $5M list.
     *
     *   historical_peak_value / _at   -> VERIFIED or OBSERVED market cap ONLY
     *                                    (CURRENT_OBSERVATION / HISTORICAL_VERIFIED)
     *   historical_estimate_fdv_usd / _at -> FDV-basis ESTIMATE ONLY
     *                                    (HISTORICAL_ESTIMATE) — informational
     *
     * The estimate is still fully preserved — in the new column here and,
     * verbatim, in `historical_peak_evidences` (basis = `fdv_total_supply`).
     * `observed_peak_market_cap` is not touched.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->double('historical_estimate_fdv_usd')->nullable()->after('historical_peak_value_at');
            $table->timestamp('historical_estimate_fdv_at')->nullable()->after('historical_estimate_fdv_usd');
        });

        // Move any existing HISTORICAL_ESTIMATE figure out of the qualification
        // column into the dedicated estimate column.
        DB::table('tokens')
            ->where('historical_peak_status', 'HISTORICAL_ESTIMATE')
            ->whereNotNull('historical_peak_value')
            ->update([
                'historical_estimate_fdv_usd' => DB::raw('historical_peak_value'),
                'historical_estimate_fdv_at' => DB::raw('historical_peak_value_at'),
                'historical_peak_value' => null,
                'historical_peak_value_at' => null,
            ]);
    }

    public function down(): void
    {
        // Restore the pre-correction layout: fold the estimate back into
        // historical_peak_value before dropping the dedicated columns.
        DB::table('tokens')
            ->where('historical_peak_status', 'HISTORICAL_ESTIMATE')
            ->whereNotNull('historical_estimate_fdv_usd')
            ->update([
                'historical_peak_value' => DB::raw('historical_estimate_fdv_usd'),
                'historical_peak_value_at' => DB::raw('historical_estimate_fdv_at'),
            ]);

        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn(['historical_estimate_fdv_usd', 'historical_estimate_fdv_at']);
        });
    }
};
