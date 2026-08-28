<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            // running | completed | failed
            $table->string('status', 16)->default('running');
            // manual | scheduled
            $table->string('trigger', 16);

            $table->unsignedInteger('raw_candidates')->nullable();
            $table->unsignedInteger('unique_candidates')->nullable();
            $table->unsignedInteger('enriched_candidates')->nullable();
            $table->unsignedInteger('age_eligible')->nullable();
            $table->unsignedInteger('snapshots_written')->nullable();
            $table->unsignedInteger('new_tokens')->nullable();
            $table->unsignedInteger('peak_updated')->nullable();
            $table->unsignedInteger('qualified')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
