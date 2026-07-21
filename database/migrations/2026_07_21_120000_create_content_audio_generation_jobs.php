<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->unsignedInteger('audio_generation_attempt')->default(0);
            $table->string('audio_storage_path')->nullable();
            $table->string('audio_storage_path_0_7')->nullable();
            $table->string('audio_storage_path_0_85')->nullable();
            $table->string('audio_storage_path_1_0')->nullable();
        });

        Schema::create('content_audio_generation_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['episode_id', 'attempt'], 'content_audio_jobs_episode_attempt_unique');
            $table->index('dialogue_id', 'content_audio_jobs_dialogue_idx');
            $table->index(['user_id', 'state'], 'content_audio_jobs_user_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_audio_generation_jobs');

        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->dropColumn([
                'audio_generation_attempt',
                'audio_storage_path',
                'audio_storage_path_0_7',
                'audio_storage_path_0_85',
                'audio_storage_path_1_0',
            ]);
        });
    }
};
