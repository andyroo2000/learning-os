<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptMatchMethod;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use App\Domain\Study\Models\LearningConcept;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningConceptSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_can_record_auditable_concept_matches(): void
    {
        $card = Card::factory()->create();
        $concept = $this->createConcept([
            'id' => 'n5-vocab-1000000-deadbeef',
            'language' => 'ja',
            'kind' => LearningConceptKind::Vocabulary,
            'jlpt_level' => 5,
            'expression' => '猫',
            'normalized_key' => '猫',
            'reading' => 'ねこ',
            'meaning' => 'cat',
            'source_name' => 'test catalog',
            'source_id' => '1000000',
            'review_status' => LearningConceptReviewStatus::Seed,
        ]);

        $card->learningConcepts()->attach($concept->id, [
            'match_method' => LearningConceptMatchMethod::Exact->value,
            'match_source' => LearningConceptMatchSource::Creation->value,
            'confidence' => 1,
            'classifier_version' => 'exact-v1',
            'evidence' => ['field' => 'answer.expression'],
        ]);

        $matchedConcept = $card->learningConcepts()->sole();

        $this->assertSame($concept->id, $matchedConcept->id);
        $this->assertSame(LearningConceptKind::Vocabulary, $matchedConcept->kind);
        $this->assertSame(LearningConceptReviewStatus::Seed, $matchedConcept->review_status);
        $this->assertSame(LearningConceptMatchMethod::Exact, $matchedConcept->pivot->match_method);
        $this->assertSame(LearningConceptMatchSource::Creation, $matchedConcept->pivot->match_source);
        $this->assertSame('1.0000', $matchedConcept->pivot->confidence);
        $this->assertSame('exact-v1', $matchedConcept->pivot->classifier_version);
        $this->assertSame(['field' => 'answer.expression'], $matchedConcept->pivot->evidence);
    }

    public function test_foreign_keys_remove_links_with_their_card_or_concept(): void
    {
        $card = Card::factory()->create();
        $concept = $this->createConcept([
            'id' => 'n5-grammar-test-pattern',
            'language' => 'ja',
            'kind' => LearningConceptKind::Grammar,
            'jlpt_level' => 5,
            'expression' => '〜てもいい',
            'normalized_key' => 'てもいい',
            'reading' => null,
            'meaning' => 'may / it is okay to',
            'source_name' => 'test catalog',
            'source_id' => 'test-pattern',
            'review_status' => LearningConceptReviewStatus::Draft,
        ]);

        $card->learningConcepts()->attach($concept->id, [
            'match_method' => LearningConceptMatchMethod::Surface->value,
            'match_source' => LearningConceptMatchSource::Backfill->value,
        ]);
        $concept->delete();

        $this->assertDatabaseMissing('card_learning_concepts', [
            'card_id' => $card->id,
            'concept_id' => $concept->id,
        ]);

        $secondConcept = $this->createConcept([
            'id' => 'n5-vocab-second-test',
            'language' => 'ja',
            'kind' => LearningConceptKind::Vocabulary,
            'jlpt_level' => 5,
            'expression' => '犬',
            'normalized_key' => '犬',
            'reading' => 'いぬ',
            'meaning' => 'dog',
            'source_name' => 'test catalog',
            'source_id' => 'second-test',
            'review_status' => LearningConceptReviewStatus::Seed,
        ]);
        $card->learningConcepts()->attach($secondConcept->id, [
            'match_method' => LearningConceptMatchMethod::Exact->value,
            'match_source' => LearningConceptMatchSource::Backfill->value,
        ]);
        $card->forceDelete();

        $this->assertDatabaseMissing('card_learning_concepts', [
            'card_id' => $card->id,
            'concept_id' => $secondConcept->id,
        ]);
    }

    public function test_actual_migration_exposes_the_match_and_coverage_indexes(): void
    {
        $learningConceptIndexes = collect(Schema::getIndexes('learning_concepts'))->pluck('name');
        $linkIndexes = collect(Schema::getIndexes('card_learning_concepts'))->pluck('name');
        $aliasIndexes = collect(Schema::getIndexes('learning_concept_aliases'))->pluck('name');

        $this->assertContains('learning_concepts_coverage_idx', $learningConceptIndexes);
        $this->assertContains('learning_concepts_match_idx', $learningConceptIndexes);
        $this->assertContains('card_learning_concepts_concept_card_idx', $linkIndexes);
        $this->assertContains('learning_concept_aliases_lookup_idx', $aliasIndexes);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('card_learning_concepts', [
            'card_id',
            'concept_id',
            'match_method',
            'match_source',
            'confidence',
            'classifier_version',
            'evidence',
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function createConcept(array $attributes): LearningConcept
    {
        $concept = new LearningConcept;
        $concept->forceFill($attributes);
        $concept->save();

        return $concept;
    }
}
