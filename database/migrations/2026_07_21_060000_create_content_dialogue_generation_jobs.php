<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->unsignedInteger('dialogue_generation_attempt')->default(0);
        });

        Schema::create('content_dialogue_generation_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['episode_id', 'attempt'], 'content_dialogue_jobs_episode_attempt_unique');
            $table->index('user_id', 'content_dialogue_jobs_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_dialogue_generation_jobs');

        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->dropColumn('dialogue_generation_attempt');
        });
    }
};
