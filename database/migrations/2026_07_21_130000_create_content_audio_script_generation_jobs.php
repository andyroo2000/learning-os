<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_audio_scripts', function (Blueprint $table): void {
            $table->unsignedInteger('render_generation_attempt')->default(0);
            $table->unsignedInteger('image_generation_attempt')->default(0);
        });

        Schema::table('content_audio_script_renders', function (Blueprint $table): void {
            $table->string('audio_storage_path')->nullable();
        });

        Schema::create('content_audio_script_generation_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('script_id')->constrained('content_audio_scripts')->cascadeOnDelete();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->string('kind', 16);
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['script_id', 'kind', 'attempt'], 'content_script_jobs_script_kind_attempt_unique');
            $table->index(['script_id', 'state'], 'content_script_jobs_script_state_idx');
            $table->index(['user_id', 'state'], 'content_script_jobs_user_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_audio_script_generation_jobs');

        Schema::table('content_audio_script_renders', function (Blueprint $table): void {
            $table->dropColumn('audio_storage_path');
        });

        Schema::table('content_audio_scripts', function (Blueprint $table): void {
            $table->dropColumn(['render_generation_attempt', 'image_generation_attempt']);
        });
    }
};
