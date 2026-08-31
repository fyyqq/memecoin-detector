<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 16B support columns:
     *
     *  - `tokens.{website_url,twitter_url,telegram_url,image_url}` — the smallest
     *    metadata the DexScreener pair `info` object actually exposes, so the
     *    TOKEN_METADATA / ORIGIN evidence collector has stored data to read
     *    without re-calling DexScreener. (DexScreener does NOT expose a token
     *    description — that field is intentionally not added.)
     *
     *  - `pump_events.evidence_collected_at` — when evidence was last collected
     *    for the event; drives the collection cooldown. Not read or written by
     *    the pump detection engine.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('website_url', 512)->nullable()->after('name');
            $table->string('twitter_url', 512)->nullable()->after('website_url');
            $table->string('telegram_url', 512)->nullable()->after('twitter_url');
            $table->string('image_url', 512)->nullable()->after('telegram_url');
            $table->timestamp('metadata_updated_at')->nullable()->after('image_url');
        });

        Schema::table('pump_events', function (Blueprint $table) {
            $table->timestamp('evidence_collected_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn(['website_url', 'twitter_url', 'telegram_url', 'image_url', 'metadata_updated_at']);
        });

        Schema::table('pump_events', function (Blueprint $table) {
            $table->dropColumn('evidence_collected_at');
        });
    }
};
