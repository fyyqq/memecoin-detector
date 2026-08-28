<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('token_id')->constrained()->cascadeOnDelete();

            // Point in time this observation was captured by our detector.
            $table->timestamp('observed_at');

            $table->double('price_usd')->nullable();
            $table->double('market_cap')->nullable();
            $table->double('fdv')->nullable();
            $table->double('liquidity_usd')->nullable();

            $table->double('volume_h24')->nullable();
            $table->double('price_change_h24')->nullable();

            $table->unsignedBigInteger('txns_h24')->nullable();
            $table->unsignedBigInteger('buys_h24')->nullable();
            $table->unsignedBigInteger('sells_h24')->nullable();

            $table->string('primary_pair_address', 128)->nullable();
            $table->string('primary_dex_id', 64)->nullable();

            $table->timestamp('earliest_pair_created_at')->nullable();

            $table->timestamps();

            $table->index(['token_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
