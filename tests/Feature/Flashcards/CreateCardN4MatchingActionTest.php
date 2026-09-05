<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateCardN4MatchingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_n4_vocabulary_and_grammar_when_a_card_is_created(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['安心かもしれない。'])
            ->andReturn([[
                ['surface' => '安心', 'base' => '安心', 'partOfSpeech' => '名詞-普通名詞-一般'],
                ['surface' => 'かも', 'base' => 'かも', 'partOfSpeech' => '助詞-副助詞'],
                ['surface' => 'しれ', 'base' => '知れる', 'partOfSpeech' => '動詞-一般'],
                ['surface' => 'ない', 'base' => 'ない', 'partOfSpeech' => '助動詞'],
                ['surface' => '。', 'base' => '。', 'partOfSpeech' => '補助記号-句点'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '安心かもしれない。',
                backText: 'It might be a relief.',
            ),
        );

        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $result->card->id,
            'concept_id' => 'n4-vocab-1153890-afd1a981',
            'match_method' => 'token',
            'match_source' => 'creation',
            'classifier_version' => 'jlpt-n5-n4-rules-v5',
        ]);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $result->card->id,
            'concept_id' => 'n4-grammar-kamoshirenai-might',
            'match_method' => 'classifier',
            'match_source' => 'creation',
            'classifier_version' => 'jlpt-n5-n4-rules-v5',
        ]);
        $this->assertSame(3, DB::table('card_learning_concepts')
            ->where('card_id', $result->card->id)
            ->count());
    }
}
