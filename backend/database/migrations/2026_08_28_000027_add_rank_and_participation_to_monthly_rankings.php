<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 25 (Top 3) — "Monthly Top Memecoins".
 *
 * `monthly_rankings` moves from ONE champion per `(year, month, chain_bucket)`
 * to a ranked **Top 3** per bucket: unique on `(year, month, chain_bucket, rank)`
 * with `rank` in `{1, 2, 3}`. At most `12 × 5 × 3 = 180` rows a year.
 *
 * The selection score changes from "observed market-cap growth" to real
 * participation:
 *
 *   score = 100 · Σ(weight · strength) / Σ(weight)      over the components that
 *                                                        are actually known
 *
 *   holder_strength     = min(1, ln(1 + holder_count)      / ln(1 + ref))
 *   volume_strength     = min(1, ln(1 + monthly_volume_usd)/ ln(1 + ref))
 *   market_cap_strength = min(1, ln(1 + month_market_cap)  / ln(1 + ref))
 *
 * default weights holder 0.40 / volume 0.35 / market_cap 0.25 (env-configurable).
 * `holder_count` is a monthly maximum / representative observation — never a
 * current count standing in for a past month; `null` means UNKNOWN and drops out
 * of the score (the weights renormalize). Market cap is SUPPORTING evidence — a
 * $150M token does not automatically beat a $20M token with stronger holders +
 * volume. `market_cap_growth_pct` / `peak_expansion_ratio` / `activity_score` are
 * retained as INFO-ONLY context, never part of the score or the ordering.
 *
 * The table is empty, so there is no data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_rankings', function (Blueprint $table): void {
            $table->dropUnique('monthly_rankings_year_month_chain_bucket_unique');

            $table->unsignedTinyInteger('rank')->default(1)->after('chain_bucket');

            // Participation inputs to the new score.
            $table->unsignedInteger('holder_count')->nullable()->after('activity_score');
            $table->double('monthly_volume_usd')->nullable()->after('holder_count');
            $table->double('month_market_cap')->nullable()->after('monthly_volume_usd');

            // The three normalized strengths [0, 1] — the transparent audit trail.
            $table->double('holder_strength')->nullable()->after('month_market_cap');
            $table->double('volume_strength')->nullable()->after('holder_strength');
            $table->double('market_cap_strength')->nullable()->after('volume_strength');

            // GeckoTerminal /info holder pass bookkeeping (per-token cooldown).
            $table->timestamp('holder_checked_at')->nullable()->after('market_cap_strength');

            $table->unique(['year', 'month', 'chain_bucket', 'rank'], 'monthly_rankings_ymbr_unique');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_rankings', function (Blueprint $table): void {
            $table->dropUnique('monthly_rankings_ymbr_unique');
            $table->dropColumn([
                'rank',
                'holder_count',
                'monthly_volume_usd',
                'month_market_cap',
                'holder_strength',
                'volume_strength',
                'market_cap_strength',
                'holder_checked_at',
            ]);
            $table->unique(['year', 'month', 'chain_bucket']);
        });
    }
};
