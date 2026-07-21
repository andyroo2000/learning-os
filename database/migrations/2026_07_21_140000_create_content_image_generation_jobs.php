<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_image_generation_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->string('state', 32);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('image_count');
            $table->uuid('claim_token')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['dialogue_id', 'state'], 'content_image_jobs_dialogue_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_image_generation_jobs');
    }
};
