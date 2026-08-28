<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();

            // Identity: (chain_id, token_address). Never the symbol.
            $table->string('chain_id', 64);
            $table->string('token_address', 128);

            $table->string('symbol', 128)->nullable();
            $table->string('name', 255)->nullable();

            // DEX pool creation time (best available age proxy). NOT token deploy time.
            $table->timestamp('earliest_pair_created_at')->nullable();

            // When our detector first / most recently saw this token.
            $table->timestamp('first_observed_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();

            // Highest market cap captured by OUR snapshots (not a guaranteed lifetime high).
            $table->double('observed_peak_market_cap')->nullable();
            $table->timestamp('observed_peak_market_cap_at')->nullable();

            $table->timestamps();

            $table->unique(['chain_id', 'token_address']);
            $table->index('chain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
