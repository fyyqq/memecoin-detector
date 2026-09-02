<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "Monthly Top Memecoins" feature (Step 25 + the Step 26 Phase 1
 * historical-research foundation).
 *
 * Deleted with it: `GET /api/memecoins/monthly-champions` + its controller, the
 * whole `App\Services\Ranking\*` + `App\Services\Historical\Research\*` trees,
 * `MonthlyRanking` / `MonthlyRankingEvidence` models, the
 * `memecoins:finalize-monthly-champion` / `memecoins:research-monthly-champions`
 * commands + the daily schedule, `config/ranking.php`, and the dashboard /
 * detail-page UI.
 *
 * `monthly_ranking_evidence` has an FK onto `monthly_rankings`, so it drops
 * first. The create/alter migrations (…000017 / …000020 / …000027 / …000028)
 * are kept for history; `down()` here recreates both tables at the schema those
 * four migrations left them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('monthly_ranking_evidence');
        Schema::dropIfExists('monthly_rankings');
    }

    public function down(): void
    {
        Schema::create('monthly_rankings', function (Blueprint $table): void {
            $table->id();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('chain_bucket', 16);
            $table->unsignedTinyInteger('rank')->default(1);

            $table->foreignId('token_id')->nullable()->constrained()->nullOnDelete();

            // Denormalized identity for a historically-researched champion.
            $table->string('champion_name', 120)->nullable();
            $table->string('champion_symbol', 64)->nullable();
            $table->string('champion_chain_id', 40)->nullable();
            $table->string('champion_token_address', 128)->nullable();
            $table->string('champion_image_url', 500)->nullable();

            $table->string('status', 32)->default('provisional');

            $table->double('performance_score')->nullable();

            // Participation inputs to the score.
            $table->unsignedInteger('holder_count')->nullable();
            $table->double('monthly_volume_usd')->nullable();
            $table->double('month_market_cap')->nullable();
            $table->double('holder_strength')->nullable();
            $table->double('volume_strength')->nullable();
            $table->double('market_cap_strength')->nullable();
            $table->timestamp('holder_checked_at')->nullable();

            // Info-only context.
            $table->double('baseline_market_cap')->nullable();
            $table->double('peak_market_cap')->nullable();
            $table->double('market_cap_growth_pct')->nullable();
            $table->double('peak_expansion_ratio')->nullable();
            $table->double('activity_score')->nullable();

            $table->unsignedInteger('observation_count')->nullable();
            $table->double('observation_coverage_ratio')->nullable();

            $table->json('scoring_breakdown')->nullable();

            $table->string('source_type', 48)->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->json('source_evidence')->nullable();
            $table->boolean('age_uncertain')->default(false);
            $table->string('confidence', 12)->nullable();

            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            $table->unique(['year', 'month', 'chain_bucket', 'rank'], 'monthly_rankings_ymbr_unique');
            $table->index(['year', 'chain_bucket']);
        });

        Schema::create('monthly_ranking_evidence', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('monthly_ranking_id')
                ->constrained('monthly_rankings')
                ->cascadeOnDelete();

            $table->string('metric', 24);
            $table->string('source_name', 160);
            $table->string('source_url', 1024)->nullable();
            $table->double('value_numeric')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('methodology', 500)->nullable();
            $table->string('basis', 16);
            $table->string('confidence', 8);
            $table->string('limitations', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('dedupe_hash', 64);

            $table->timestamps();

            $table->unique(['monthly_ranking_id', 'dedupe_hash'], 'mre_ranking_dedupe_unique');
            $table->index(['monthly_ranking_id', 'metric'], 'mre_ranking_metric_index');
        });
    }
};
