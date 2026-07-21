<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Keep historical DDL independent from application constants that may evolve.
    private const CONVOLAB_SOURCE = 'convolab';

    public function up(): void
    {
        Schema::create('content_source_locks', function (Blueprint $table): void {
            $table->string('source_system', 32)->primary();
        });

        DB::table('content_source_locks')->insert([
            'source_system' => self::CONVOLAB_SOURCE,
        ]);

        Schema::table('content_episodes', function (Blueprint $table): void {
            // Every row predating Learning-owned writes came from the Convo Lab importer.
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            // Import replacement deletes by source system without scanning preserved rows.
            $table->index('source_system', 'content_episodes_source_system_idx');
            $table->index(
                ['user_id', 'convolab_user_id', 'updated_at', 'id'],
                'content_episodes_user_source_updated_id_idx',
            );
        });

        Schema::table('content_courses', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            $table->index('source_system', 'content_courses_source_system_idx');
            $table->index(
                ['user_id', 'convolab_user_id', 'updated_at', 'id'],
                'content_courses_user_source_updated_id_idx',
            );
        });

        Schema::table('content_audio_script_media', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            $table->index('source_system', 'content_audio_media_source_system_idx');
        });

        Schema::table('content_episode_courses', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            $table->index('source_system', 'content_episode_courses_source_system_idx');
        });

        Schema::create('content_episode_tombstones', function (Blueprint $table): void {
            $table->uuid('episode_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->timestampTz('deleted_at');

            $table->index(
                ['user_id', 'convolab_user_id'],
                'content_episode_tombstones_user_source_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_episode_tombstones');

        Schema::table('content_episode_courses', function (Blueprint $table): void {
            $table->dropIndex('content_episode_courses_source_system_idx');
            $table->dropColumn('source_system');
        });

        Schema::table('content_audio_script_media', function (Blueprint $table): void {
            $table->dropIndex('content_audio_media_source_system_idx');
            $table->dropColumn('source_system');
        });

        Schema::table('content_courses', function (Blueprint $table): void {
            $table->dropIndex('content_courses_user_source_updated_id_idx');
            $table->dropIndex('content_courses_source_system_idx');
            $table->dropColumn('source_system');
        });

        Schema::table('content_episodes', function (Blueprint $table): void {
            $table->dropIndex('content_episodes_user_source_updated_id_idx');
            $table->dropIndex('content_episodes_source_system_idx');
            $table->dropColumn('source_system');
        });

        Schema::dropIfExists('content_source_locks');
    }
};
