<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 14 — discovery coverage metrics. Aggregate counts only (no raw
     * candidate rows). Served by GET /api/memecoins/discovery-status.
     */
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->unsignedInteger('selected_for_enrichment')->nullable()->after('enriched_candidates');
            $table->unsignedInteger('candidate_cap_dropped')->nullable()->after('selected_for_enrichment');
            $table->unsignedSmallInteger('search_terms_used')->nullable()->after('candidate_cap_dropped');
            $table->unsignedSmallInteger('search_terms_with_results')->nullable()->after('search_terms_used');
            // { "<chain_id>": <unique candidate count>, ... }
            $table->json('chains_discovered')->nullable()->after('search_terms_with_results');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->dropColumn([
                'selected_for_enrichment',
                'candidate_cap_dropped',
                'search_terms_used',
                'search_terms_with_results',
                'chains_discovered',
            ]);
        });
    }
};
