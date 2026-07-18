<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_vocab_variant_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_word', 500);
            $table->string('target_reading', 1000)->nullable();
            $table->string('target_meaning', 1000)->nullable();
            $table->text('source_sentence')->nullable();
            $table->text('source_context')->nullable();
            $table->boolean('include_learner_context')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id'], 'study_vocab_groups_user_created_id_idx');
            $table->index(['user_id', 'target_word'], 'study_vocab_groups_user_target_idx');
        });

        Schema::create('study_vocab_variant_sentences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('variant_group_id')
                ->constrained('study_vocab_variant_groups')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('ordinal');
            $table->text('sentence_jp');
            $table->text('sentence_reading')->nullable();
            $table->text('sentence_en');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['variant_group_id', 'ordinal'],
                'study_vocab_sentences_group_ordinal_unique',
            );
            $table->index(
                ['user_id', 'variant_group_id'],
                'study_vocab_sentences_user_group_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_vocab_variant_sentences');
        Schema::dropIfExists('study_vocab_variant_groups');
    }
};
