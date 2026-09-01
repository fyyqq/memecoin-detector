<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 25 — Historical Monthly Champion Backfill.
 *
 * For PAST completed months we actively research external / historical market
 * sources to identify the best-supported #1 performer per chain bucket — we do
 * NOT just return "no champion" because our MarketSnapshot history only starts
 * in late August 2026.
 *
 * A historically-researched champion may not exist in our `tokens` table
 * (discovery only started recently), so its identity is stored **denormalized**
 * on the ranking row (`champion_*`); `token_id` still links to a `Token` when we
 * actually track it. `tokens.chain_id` is never touched.
 *
 * `source_type` now distinguishes:
 *   internal_observed                    — from our own MarketSnapshots
 *   exact_dexscreener_rank               — a source directly establishes the
 *                                          DexScreener historical rank
 *   best_supported_historical_performer  — the best-supported candidate from
 *                                          historical evidence (NOT a claimed
 *                                          exact DexScreener rank)
 *
 * `source_evidence` is a short list of `{name, url, claim, published_at}` — the
 * research provenance. No giant web-page bodies are ever stored.
 *
 * `age_uncertain` — the 30-day-window launch/pool age could not be established
 * from defensible evidence; confidence is reduced accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_rankings', function (Blueprint $table): void {
            // `best_supported_historical_performer` is 34 chars — widen the column.
            $table->string('source_type', 48)->nullable()->change();

            // Denormalized champion identity for historically-researched winners
            // that are not in our `tokens` table. Nullable — populated only for
            // web-research rows; `token_id` is used when we track the token.
            $table->string('champion_name', 120)->nullable()->after('token_id');
            $table->string('champion_symbol', 64)->nullable()->after('champion_name');
            $table->string('champion_chain_id', 40)->nullable()->after('champion_symbol');
            $table->string('champion_token_address', 128)->nullable()->after('champion_chain_id');
            $table->string('champion_image_url', 500)->nullable()->after('champion_token_address');

            // Research provenance — a short list of {name, url, claim, published_at}.
            $table->json('source_evidence')->nullable()->after('source_reference');

            // The 30-day age window could not be established from evidence.
            $table->boolean('age_uncertain')->default(false)->after('source_evidence');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_rankings', function (Blueprint $table): void {
            $table->dropColumn([
                'champion_name',
                'champion_symbol',
                'champion_chain_id',
                'champion_token_address',
                'champion_image_url',
                'source_evidence',
                'age_uncertain',
            ]);
        });
    }
};
