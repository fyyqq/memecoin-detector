<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized headline of the token's current historical-peak evidence.
     *
     * These are SEPARATE from `observed_peak_market_cap` on purpose:
     * `observed_peak_market_cap` stays OUR OWN snapshot peak and is never
     * overwritten with an external / estimated value. These columns hold the
     * qualification engine's determination (which may be CURRENT_OBSERVATION,
     * HISTORICAL_VERIFIED, HISTORICAL_ESTIMATE, or UNKNOWN) so the read API can
     * filter/sort without a join. Full detail lives in
     * `historical_peak_evidences`.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->double('historical_peak_value')->nullable()->after('observed_peak_market_cap_at');
            $table->timestamp('historical_peak_value_at')->nullable()->after('historical_peak_value');
            $table->string('historical_peak_status', 32)->nullable()->after('historical_peak_value_at');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn(['historical_peak_value', 'historical_peak_value_at', 'historical_peak_status']);
        });
    }
};
