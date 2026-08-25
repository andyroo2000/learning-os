<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wanikani_connections', function (Blueprint $table): void {
            // Vocabulary needs its own cursor because existing connections have already
            // advanced the kanji-only assignment cursor past their historical lessons.
            $table->timestamp('vocabulary_assignments_synced_through_at', 6)
                ->nullable()
                ->after('assignments_synced_through_at');
        });

        Schema::create('wanikani_subjects', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->primary();
            $table->string('subject_type', 32);
            $table->string('characters', 500);
            $table->string('normalized_key', 500);
            $table->json('readings');
            $table->json('meanings');
            $table->timestamp('hidden_at', 6)->nullable();
            $table->timestamp('source_updated_at', 6)->nullable();
            // Subjects without a current version are rematched on the user's next sync,
            // including subjects that no longer appear in WaniKani's incremental feed.
            $table->string('matcher_version', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('user_wanikani_assignments', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subject_id');
            $table->unsignedTinyInteger('srs_stage');
            $table->timestamp('passed_at', 6)->nullable();
            $table->timestamp('burned_at', 6)->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamp('source_updated_at', 6)->nullable();
            $table->timestamps();

            $table->foreign('subject_id')->references('subject_id')->on('wanikani_subjects')->cascadeOnDelete();
            $table->primary(['user_id', 'subject_id'], 'user_wanikani_assignments_pk');
            $table->index(
                ['user_id', 'passed_at', 'subject_id'],
                'wk_assignments_user_passed_subject_idx',
            );
        });

        Schema::create('wanikani_subject_learning_concepts', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id');
            $table->string('concept_id', 100);
            $table->string('match_method', 32);
            $table->decimal('confidence', 5, 4);
            $table->string('matcher_version', 100);
            $table->timestamps();

            $table->foreign('subject_id')->references('subject_id')->on('wanikani_subjects')->cascadeOnDelete();
            $table->foreign('concept_id')->references('id')->on('learning_concepts')->cascadeOnDelete();
            $table->primary(['subject_id', 'concept_id'], 'wk_subject_concepts_pk');
            $table->index(['concept_id', 'subject_id'], 'wk_subject_concepts_concept_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wanikani_subject_learning_concepts');
        Schema::dropIfExists('user_wanikani_assignments');
        Schema::dropIfExists('wanikani_subjects');

        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->dropColumn('vocabulary_assignments_synced_through_at');
        });
    }
};
