<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use App\Domain\Study\Actions\GetJlptMasteryAction;
use App\Domain\Study\Actions\MatchLearningConceptsForCardAction;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Services\WaniKaniVocabularyConceptMatcher;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedJlptCoverageTest extends TestCase
{
    use RefreshDatabase;

    public static function levels(): array
    {
        return [
            [['N3', '愛', 'あい', 'n3-vocab-1150410-77660e89', ['に', 'つい', 'て'], 'n3-grammar-ni-tsuite', 1730, 130]],
            [['N2', '曖昧', 'あいまい', 'n2-vocab-1567920-7f247082', ['を', '問わ', 'ず'], 'n2-grammar-wo-towazu-regardless', 1812, 149]],
            [['N1', '愛想', 'あいそ', 'n1-vocab-1575660-40b09e96', ['と', '思い', 'きや'], 'n1-grammar-to-omoi-kiya', 3423, 150]],
        ];
    }

    #[DataProvider('levels')]
    public function test_cards_match_and_coverage_is_scoped_to_the_learner(array $scenario): void
    {
        [$level, $word, $reading, $vocabId, $surfaces, $grammarId, $vocabularyTotal, $grammarTotal] = $scenario;
        $tokenizer = $this->createMock(JapaneseTokenizer::class);
        $tokens = array_map(fn (string $surface): array => ['surface' => $surface, 'base' => $surface], $surfaces);
        $tokenizer->method('tokenize')->willReturnCallback(fn (array $texts): array => array_fill(0, count($texts), $tokens));
        $tokenizer->method('hadFailure')->willReturn(false);
        $this->app->instance(JapaneseTokenizer::class, $tokenizer);
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create([
            'front_text' => $word,
            'back_text' => implode('', $surfaces),
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 7],
        ]);
        $matcher = app(MatchLearningConceptsForCardAction::class);
        $matcher->handle($card, LearningConceptMatchSource::Backfill);
        $matcher->handle($card, LearningConceptMatchSource::Backfill);
        foreach ([$vocabId, $grammarId] as $id) {
            $this->assertSame(1, DB::table('card_learning_concepts')->where('card_id', $card->id)->where('concept_id', $id)->count());
        }
        $mastery = app(GetJlptMasteryAction::class)->handle($user->id);
        $this->assertSame(['N5', 'N4', 'N3', 'N2', 'N1'], array_keys($mastery));
        foreach (['vocabulary' => $vocabularyTotal, 'grammar' => $grammarTotal] as $kind => $total) {
            $this->assertSame($total, $mastery[$level][$kind]['total']);
            $this->assertSame(1, $mastery[$level][$kind]['known']);
            $this->assertSame(1, $mastery[$level][$kind]['matched']);
        }
        $other = app(GetJlptMasteryAction::class)->handle(User::factory()->create()->id);
        $this->assertSame(0, $other[$level]['vocabulary']['known']);
        $this->assertSame(0, $other[$level]['grammar']['known']);
        $resource = (new StudyOverviewCompatibilityResource(['jlpt_mastery' => $mastery]))->toArray(Request::create('/'));
        $this->assertSame(1, $resource['jlptMastery'][$level]['grammar']['knownFromCards']);
        $matches = app(WaniKaniVocabularyConceptMatcher::class)->matchSubjects([
            ['subject_id' => 123, 'characters' => $word, 'readings' => [$reading]],
        ]);
        $this->assertSame($vocabId, $matches[0]['concept_id']);
    }

    public function test_catalog_rollback_preserves_other_levels_and_can_be_reimported(): void
    {
        $migration = require base_path('database/migrations/2026_11_10_120000_import_n3_n2_n1_learning_concepts.php');
        $migration->down();
        $this->assertSame(0, DB::table('learning_concepts')->whereIn('jlpt_level', [1, 2, 3])->count());
        $this->assertSame(1490, DB::table('learning_concepts')->whereIn('jlpt_level', [4, 5])->count());
        $migration->up();
        $this->assertSame(7394, DB::table('learning_concepts')->whereIn('jlpt_level', [1, 2, 3])->count());
    }
}
