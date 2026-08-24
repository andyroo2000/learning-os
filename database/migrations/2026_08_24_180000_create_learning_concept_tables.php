<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_concepts', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->string('language', 8);
            $table->string('kind', 32);
            $table->unsignedTinyInteger('jlpt_level');
            $table->string('expression', 500);
            $table->string('normalized_key', 500);
            $table->string('reading', 500)->nullable();
            $table->string('normalized_reading', 500)->nullable();
            $table->text('meaning');
            $table->string('source_name');
            $table->string('source_id');
            $table->string('review_status', 32);
            $table->timestamps();

            $table->index(
                ['language', 'jlpt_level', 'kind', 'review_status'],
                'learning_concepts_coverage_idx',
            );
            $table->index(
                ['language', 'kind', 'normalized_key'],
                'learning_concepts_match_idx',
            );
        });

        Schema::create('learning_concept_aliases', function (Blueprint $table): void {
            $table->string('concept_id', 100);
            $table->string('alias_kind', 32);
            $table->string('normalized_key', 500);

            $table->foreign('concept_id')
                ->references('id')
                ->on('learning_concepts')
                ->cascadeOnDelete();
            $table->primary(
                ['concept_id', 'alias_kind', 'normalized_key'],
                'learning_concept_aliases_pk',
            );
            $table->index(
                ['alias_kind', 'normalized_key', 'concept_id'],
                'learning_concept_aliases_lookup_idx',
            );
        });

        Schema::create('card_learning_concepts', function (Blueprint $table): void {
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->string('concept_id', 100);
            $table->string('match_method', 32);
            $table->string('match_source', 32);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('classifier_version', 100)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->foreign('concept_id')
                ->references('id')
                ->on('learning_concepts')
                ->cascadeOnDelete();
            $table->primary(
                ['card_id', 'concept_id'],
                'card_learning_concepts_pk',
            );
            $table->index(
                ['concept_id', 'card_id'],
                'card_learning_concepts_concept_card_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_learning_concepts');
        Schema::dropIfExists('learning_concept_aliases');
        Schema::dropIfExists('learning_concepts');
    }
};
