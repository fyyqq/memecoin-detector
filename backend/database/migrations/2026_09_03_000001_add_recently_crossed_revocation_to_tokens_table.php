<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red-flag revocation of the "previously approved by Recently Crossed" stamp.
 *
 * `recently_crossed_qualified_at` (migration `..._000031`) is normally written
 * once and never cleared. After the 2026-09 "pippo" incident — a 2-hour-old
 * pump that got stamped at its peak then rugged — the marker
 * (`memecoins:mark-recently-crossed`) gains a revocation pass: a token that now
 * trips a HARD red flag (momentum anomaly / post-crossing collapse /
 * unscreenable chain) has its stamp nulled and the reason recorded here for
 * observability. A SOFT miss (gentle cool, stale discovery, a covered-chain
 * HIGH/CRITICAL rescreen) still preserves the stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table): void {
            $table->timestamp('recently_crossed_revoked_at')
                ->nullable()
                ->after('recently_crossed_qualified_at');

            $table->string('recently_crossed_revoked_reason', 40)
                ->nullable()
                ->after('recently_crossed_revoked_at');

            $table->index('recently_crossed_revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table): void {
            $table->dropIndex(['recently_crossed_revoked_at']);
            $table->dropColumn(['recently_crossed_revoked_at', 'recently_crossed_revoked_reason']);
        });
    }
};
