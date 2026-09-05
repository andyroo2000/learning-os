<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateCardTokenizationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_tokenize_bracket_furigana_as_sentence_vocabulary(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['本'])
            ->andReturn([[
                ['surface' => '本', 'base' => '本'],
            ]]);
        $deck = Deck::factory()->create();

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '本[ほん]',
                backText: 'book',
            ),
        );
    }

    public function test_it_strips_inline_furigana_readings_before_sentence_tokenization(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['新しい 本を 読みました。'])
            ->andReturn([[
                ['surface' => '新しい', 'base' => '新しい'],
                ['surface' => '本', 'base' => '本'],
                ['surface' => '読み', 'base' => '読む'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '新しい 本[ほん]を 読[よ]みました。',
                backText: 'I read a new book.',
            ),
        );

        foreach ([
            'n5-vocab-1361490-8ae27e3b',
            'n5-vocab-1522150-0e8f798d',
            'n5-vocab-1456360-eb7e6759',
        ] as $conceptId) {
            $this->assertDatabaseHas('card_learning_concepts', [
                'card_id' => $result->card->id,
                'concept_id' => $conceptId,
                'match_method' => 'token',
            ]);
        }
    }

    public function test_it_tokenizes_inline_furigana_when_the_sentence_ends_with_a_bracket_reading(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['これは 本'])
            ->andReturn([[
                ['surface' => 'これ', 'base' => 'これ'],
                ['surface' => '本', 'base' => '本'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'これは 本[ほん]',
                backText: 'This is a book.',
            ),
        );

        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $result->card->id,
            'concept_id' => 'n5-vocab-1522150-0e8f798d',
            'match_method' => 'token',
        ]);
    }

    public function test_it_classifies_a_noun_copula_without_fanning_out_to_adjective_concepts(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['学生です。'])
            ->andReturn([[
                ['surface' => '学生', 'base' => '学生', 'partOfSpeech' => '名詞-普通名詞-一般'],
                ['surface' => 'です', 'base' => 'です', 'partOfSpeech' => '助動詞'],
                ['surface' => '。', 'base' => '。', 'partOfSpeech' => '補助記号-句点'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '学生です。',
                backText: 'I am a student.',
            ),
        );

        $grammarMatches = DB::table('card_learning_concepts as links')
            ->join('learning_concepts as concepts', 'concepts.id', '=', 'links.concept_id')
            ->where('links.card_id', $result->card->id)
            ->where('concepts.kind', 'grammar')
            ->pluck('concepts.id');

        $this->assertSame(['n5-grammar-desu-polite-copula'], $grammarMatches->all());
    }

    public function test_it_skips_automatic_grammar_links_when_tokenization_is_unavailable(): void
    {
        config()->set('services.mecab.binary', '/definitely-missing/convolab-mecab');
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '学生です。',
                backText: 'I am a student.',
            ),
        );

        $this->assertSame(0, DB::table('card_learning_concepts as links')
            ->join('learning_concepts as concepts', 'concepts.id', '=', 'links.concept_id')
            ->where('links.card_id', $result->card->id)
            ->where('concepts.kind', 'grammar')
            ->count());
    }
}
