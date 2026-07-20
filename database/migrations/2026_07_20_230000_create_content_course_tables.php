<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_sample_content')->default(false);
            $table->boolean('is_test_course')->default(false);
            $table->string('native_language', 16);
            $table->string('target_language', 16);
            $table->unsignedInteger('max_lesson_duration_minutes')->default(30);
            $table->string('l1_voice_id');
            $table->string('l1_voice_provider')->nullable();
            $table->string('jlpt_level', 8)->nullable();
            $table->string('speaker1_gender', 32);
            $table->string('speaker2_gender', 32);
            $table->string('speaker1_voice_id')->nullable();
            $table->string('speaker1_voice_provider')->nullable();
            $table->string('speaker2_voice_id')->nullable();
            $table->string('speaker2_voice_provider')->nullable();
            $table->json('script_json')->nullable();
            $table->json('script_units_json')->nullable();
            $table->unsignedInteger('approx_duration_seconds')->nullable();
            $table->text('audio_url')->nullable();
            $table->json('timing_data')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'updated_at', 'id'], 'content_courses_user_updated_id_idx');
            $table->index(['user_id', 'status', 'updated_at', 'id'], 'content_courses_user_status_updated_idx');
        });

        Schema::create('content_course_core_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('content_courses')->cascadeOnDelete();
            $table->text('text_l2');
            $table->text('reading_l2')->nullable();
            $table->text('translation_l1');
            $table->double('complexity_score');
            $table->uuid('source_episode_id')->nullable();
            $table->uuid('source_sentence_id')->nullable();
            $table->unsignedInteger('source_unit_index')->nullable();
            $table->json('components')->nullable();

            $table->index('course_id', 'content_course_core_items_course_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_course_core_items');
        Schema::dropIfExists('content_courses');
    }
};
