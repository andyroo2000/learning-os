<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_audio_practices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('convolab_user_id')->nullable();
            $table->date('practice_date');
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('target_duration_minutes')->default(30);
            $table->string('target_language', 16)->default('ja');
            $table->string('native_language', 16)->default('en');
            $table->json('source_card_ids_json')->nullable();
            $table->json('selection_summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'practice_date']);
            $table->index(['user_id', 'status', 'practice_date']);
            $table->index('status');
        });

        Schema::create('daily_audio_practice_tracks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('practice_id');
            $table->string('mode', 32);
            $table->string('status', 32)->default('draft');
            $table->string('title');
            $table->unsignedSmallInteger('sort_order');
            $table->json('script_units_json')->nullable();
            $table->text('audio_url')->nullable();
            $table->json('timing_data')->nullable();
            $table->unsignedInteger('approx_duration_seconds')->nullable();
            $table->json('generation_metadata_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('practice_id')->references('id')->on('daily_audio_practices')->cascadeOnDelete();
            $table->unique(['practice_id', 'mode']);
            $table->index(['practice_id', 'sort_order']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_audio_practice_tracks');
        Schema::dropIfExists('daily_audio_practices');
    }
};
