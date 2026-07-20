<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_episodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->string('title');
            $table->text('source_text');
            $table->string('target_language', 16);
            $table->string('native_language', 16);
            $table->string('content_type', 32)->default('dialogue');
            $table->string('jlpt_level', 8)->nullable();
            $table->boolean('auto_generate_audio')->default(true);
            $table->string('status', 32)->default('draft');
            $table->boolean('is_sample_content')->default(false);
            $table->text('audio_url')->nullable();
            $table->string('audio_speed', 32)->nullable();
            $table->text('audio_url_0_7')->nullable();
            $table->text('audio_url_0_85')->nullable();
            $table->text('audio_url_1_0')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'updated_at', 'id'], 'content_episodes_user_updated_id_idx');
            $table->index(['user_id', 'content_type'], 'content_episodes_user_type_idx');
            $table->index(['user_id', 'status'], 'content_episodes_user_status_idx');
        });

        Schema::create('content_dialogues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->unique()->constrained('content_episodes')->cascadeOnDelete();
            $table->timestampsTz();
        });

        Schema::create('content_speakers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();
            $table->string('name');
            $table->string('voice_id');
            $table->string('voice_provider')->nullable();
            $table->string('proficiency', 32);
            $table->string('tone', 32);
            $table->string('gender', 32)->nullable();
            $table->string('color', 64)->nullable();
            $table->text('avatar_url')->nullable();
            $table->index('dialogue_id');
        });

        Schema::create('content_sentences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();
            $table->foreignUuid('speaker_id')->constrained('content_speakers');
            $table->unsignedInteger('sort_order');
            $table->text('text');
            $table->text('translation');
            $table->json('metadata');
            $table->text('audio_url')->nullable();
            $table->integer('start_time')->nullable();
            $table->integer('end_time')->nullable();
            $table->integer('start_time_0_7')->nullable();
            $table->integer('end_time_0_7')->nullable();
            $table->integer('start_time_0_85')->nullable();
            $table->integer('end_time_0_85')->nullable();
            $table->integer('start_time_1_0')->nullable();
            $table->integer('end_time_1_0')->nullable();
            $table->json('variations')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestampsTz();
            $table->index(['dialogue_id', 'sort_order', 'id'], 'content_sentences_dialogue_order_id_idx');
        });

        Schema::create('content_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->text('url');
            $table->text('prompt');
            $table->unsignedInteger('sort_order');
            $table->uuid('sentence_start_id')->nullable();
            $table->uuid('sentence_end_id')->nullable();
            $table->timestampTz('created_at');
            $table->index(['episode_id', 'sort_order', 'id'], 'content_images_episode_order_id_idx');
        });

        Schema::create('content_audio_scripts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->unique()->constrained('content_episodes')->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->string('image_status', 32)->default('pending');
            $table->text('image_error_message')->nullable();
            $table->string('voice_id');
            $table->string('voice_provider')->default('google');
            $table->json('generation_metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });

        Schema::create('content_audio_script_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 32)->default('generated');
            $table->string('source_filename');
            $table->string('normalized_filename');
            $table->string('media_kind', 32)->default('image');
            $table->string('content_type')->nullable();
            $table->text('storage_path')->nullable();
            $table->text('public_url')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'updated_at', 'id'], 'content_audio_media_user_updated_id_idx');
        });

        Schema::create('content_audio_script_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('script_id')->constrained('content_audio_scripts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('text');
            $table->text('reading')->nullable();
            $table->text('translation');
            $table->text('image_prompt')->nullable();
            $table->string('image_status', 32)->default('pending');
            $table->text('image_error_message')->nullable();
            $table->foreignUuid('image_media_id')->nullable()->constrained('content_audio_script_media')->nullOnDelete();
            $table->timestampTz('image_generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['script_id', 'sort_order'], 'content_audio_segments_script_order_unique');
        });

        Schema::create('content_audio_script_renders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('script_id')->constrained('content_audio_scripts')->cascadeOnDelete();
            $table->string('speed', 16);
            $table->double('numeric_speed');
            $table->string('status', 32)->default('draft');
            $table->text('audio_url')->nullable();
            $table->json('timing_data')->nullable();
            $table->double('approx_duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();
            $table->unique(['script_id', 'speed'], 'content_audio_renders_script_speed_unique');
        });

        Schema::create('content_episode_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->uuid('convolab_course_id');
            $table->unsignedInteger('sort_order');
            $table->unique(['convolab_course_id', 'episode_id'], 'content_episode_courses_course_episode_unique');
            $table->index('episode_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_episode_courses');
        Schema::dropIfExists('content_audio_script_renders');
        Schema::dropIfExists('content_audio_script_segments');
        Schema::dropIfExists('content_audio_script_media');
        Schema::dropIfExists('content_audio_scripts');
        Schema::dropIfExists('content_images');
        Schema::dropIfExists('content_sentences');
        Schema::dropIfExists('content_speakers');
        Schema::dropIfExists('content_dialogues');
        Schema::dropIfExists('content_episodes');
    }
};
