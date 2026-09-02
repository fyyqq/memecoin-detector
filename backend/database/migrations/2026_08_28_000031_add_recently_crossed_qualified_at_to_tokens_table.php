<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-30-Day Memecoin Tracking.
 *
 * `recently_crossed_qualified_at` records the FIRST time a token satisfied the
 * ENTIRE "🔥 Recently Crossed $5M" predicate (age <= 30d + representative $5M
 * crossing inside the window + verified/observed peak in [$5M, $1B) + every
 * deterministic quality gate in App\Services\Historical\RecentlyCrossedQualifier).
 *
 * It is the persisted "previously approved by Recently Crossed" marker: written
 * once by `memecoins:mark-recently-crossed`, NEVER cleared and NEVER rewritten,
 * so a token that later dumps below $5M, loses discovery freshness, or is
 * re-screened as HIGH/CRITICAL still keeps its historical approval lineage.
 *
 * `GET /api/memecoins/post-30-day` reads this column together with the existing
 * `earliest_pair_created_at` age rule (age > 30d). Recently Crossed (age <= 30d)
 * and Post-30-Day (age > 30d) can never overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table): void {
            $table->timestamp('recently_crossed_qualified_at')
                ->nullable()
                ->after('last_observed_at');

            $table->index('recently_crossed_qualified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table): void {
            $table->dropIndex(['recently_crossed_qualified_at']);
            $table->dropColumn('recently_crossed_qualified_at');
        });
    }
};
