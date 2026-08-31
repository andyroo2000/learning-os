<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_progress_projections', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('projection_version');
            $table->json('metric_values');
            $table->json('threshold_reached_at');
            $table->unsignedInteger('current_correct_run')->default(0);
            $table->unsignedBigInteger('conversation_ms')->default(0);
            $table->unsignedBigInteger('listening_ms')->default(0);
            $table->timestamp('last_review_created_at', 3)->nullable();
            $table->ulid('last_review_id')->nullable();
            $table->timestamp('latest_reviewed_at', 3)->nullable();
            $table->ulid('latest_reviewed_id')->nullable();
            $table->timestamp('latest_study_ended_at', 3)->nullable();
            $table->boolean('needs_rebuild')->default(false);
            $table->timestamps();
        });

        Schema::create('achievement_card_projections', function (Blueprint $table): void {
            $table->foreignUlid('card_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->double('maximum_stability')->default(0);
            $table->timestamp('last_reviewed_at', 3)->nullable();
            $table->timestamp('source_updated_at', 3)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'card_id'], 'achievement_card_projection_user_card_idx');
        });

        Schema::create('achievement_study_session_projections', function (Blueprint $table): void {
            $table->ulid('study_activity_session_id')->primary();
            $table->foreign('study_activity_session_id', 'achievement_study_session_id_fk')
                ->references('id')
                ->on('study_activity_sessions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('study_day');
            $table->timestamp('ended_at', 3);
            $table->string('category', 24);
            $table->unsignedInteger('conversation_ms')->default(0);
            $table->unsignedInteger('listening_ms')->default(0);
            $table->string('daily_audio_episode', 120)->nullable();
            $table->timestamp('source_updated_at', 3);
            $table->timestamps();

            $table->index(
                ['user_id', 'study_day', 'category'],
                'achievement_study_projection_user_day_category_idx',
            );
            $table->index(
                ['user_id', 'daily_audio_episode', 'study_day'],
                'achievement_study_projection_user_episode_day_idx',
            );
        });

        Schema::table('card_review_events', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'card_review_events_created_at_id_idx');
        });
        Schema::table('cards', function (Blueprint $table): void {
            $table->index(['deck_id', 'updated_at', 'id'], 'cards_deck_updated_id_idx');
        });
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->index(['user_id', 'updated_at', 'id'], 'study_sessions_user_updated_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('study_activity_sessions', function (Blueprint $table): void {
            $table->dropIndex('study_sessions_user_updated_id_idx');
        });
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex('cards_deck_updated_id_idx');
        });
        Schema::table('card_review_events', function (Blueprint $table): void {
            $table->dropIndex('card_review_events_created_at_id_idx');
        });

        Schema::dropIfExists('achievement_study_session_projections');
        Schema::dropIfExists('achievement_card_projections');
        Schema::dropIfExists('achievement_progress_projections');
    }
};
