<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 19 — trending-meta-first discovery coverage metrics. Aggregate counts
     * only (no raw candidate rows). Served by GET /api/memecoins/discovery-status.
     */
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('trending_meta_count')->nullable()->after('chains_discovered');
            $table->unsignedInteger('trending_meta_pairs_seen')->nullable()->after('trending_meta_count');
            $table->unsignedInteger('trending_meta_unique_candidates')->nullable()->after('trending_meta_pairs_seen');
            // Unique candidates that entered prioritization after the trending-meta
            // market-data pre-filter + source union.
            $table->unsignedInteger('pre_filtered_candidates')->nullable()->after('trending_meta_unique_candidates');
            // { "trending_meta": n, "profile": n, "boost": n, "search": n }
            $table->json('discovery_source_counts')->nullable()->after('pre_filtered_candidates');
            // list of trending-meta slugs expanded this run
            $table->json('trending_meta_slugs_used')->nullable()->after('discovery_source_counts');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->dropColumn([
                'trending_meta_count',
                'trending_meta_pairs_seen',
                'trending_meta_unique_candidates',
                'pre_filtered_candidates',
                'discovery_source_counts',
                'trending_meta_slugs_used',
            ]);
        });
    }
};
