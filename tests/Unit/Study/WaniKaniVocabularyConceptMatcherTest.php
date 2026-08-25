<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use App\Domain\Study\Services\WaniKaniVocabularyConceptMatcher;
use App\Domain\Study\Support\LearningConceptText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WaniKaniVocabularyConceptMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_expression_and_reading_matching_avoids_ambiguous_homographs(): void
    {
        $this->concept('test-red', '架空赤', 'かくうあか');
        $this->concept('test-raw', '生', 'なま');
        $this->concept('test-life', '生', 'せい');
        $this->concept('test-ambiguous-a', '橋', 'はし');
        $this->concept('test-ambiguous-b', '橋', 'はし');
        $this->concept('test-snack', 'おやつ', 'おやつ');

        $matches = app(WaniKaniVocabularyConceptMatcher::class)->matchSubjects([
            ['subject_id' => 1, 'characters' => '架空赤', 'readings' => ['かくうあか']],
            ['subject_id' => 2, 'characters' => '生', 'readings' => ['せい']],
            ['subject_id' => 3, 'characters' => '橋', 'readings' => ['はし']],
            ['subject_id' => 4, 'characters' => 'おやつ', 'readings' => ['おやつ']],
        ]);

        $this->assertSame([
            [
                'subject_id' => 1,
                'concept_id' => 'test-red',
                'match_method' => 'expression',
                'confidence' => 1.0,
            ],
            [
                'subject_id' => 2,
                'concept_id' => 'test-life',
                'match_method' => 'expression_reading',
                'confidence' => 1.0,
            ],
            [
                'subject_id' => 4,
                'concept_id' => 'test-snack',
                'match_method' => 'expression',
                'confidence' => 1.0,
            ],
        ], $matches);
    }

    private function concept(string $id, string $expression, string $reading): void
    {
        $now = now();
        DB::table('learning_concepts')->insert([
            'id' => $id,
            'language' => 'ja',
            'kind' => LearningConceptKind::Vocabulary->value,
            'jlpt_level' => 4,
            'expression' => $expression,
            'normalized_key' => LearningConceptText::normalize($expression),
            'reading' => $reading,
            'normalized_reading' => LearningConceptText::normalize($reading),
            'meaning' => 'test meaning',
            'source_name' => 'matcher unit test',
            'source_id' => $id,
            'review_status' => LearningConceptReviewStatus::Seed->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('learning_concept_aliases')->insert([
            [
                'concept_id' => $id,
                'alias_kind' => 'expression',
                'normalized_key' => LearningConceptText::normalize($expression),
            ],
            [
                'concept_id' => $id,
                'alias_kind' => 'reading',
                'normalized_key' => LearningConceptText::normalize($reading),
            ],
        ]);
    }
}
