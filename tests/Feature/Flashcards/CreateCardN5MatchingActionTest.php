<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardN5MatchingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_n5_vocabulary_and_grammar_when_a_card_is_created_without_duplicate_retry_links(): void
    {
        $sentenceTokens = [
            ['surface' => '会社', 'base' => '会社', 'partOfSpeech' => '名詞-普通名詞-一般'],
            ['surface' => 'が', 'base' => 'が', 'partOfSpeech' => '助詞-格助詞'],
            ['surface' => 'あり', 'base' => '有る', 'partOfSpeech' => '動詞-非自立可能'],
            ['surface' => 'ます', 'base' => 'ます', 'partOfSpeech' => '助動詞'],
            ['surface' => '。', 'base' => '。', 'partOfSpeech' => '補助記号-句点'],
        ];
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['会社があります。', '会社があります。', '会社', '会社があります。'])
            ->andReturn([$sentenceTokens, $sentenceTokens, [[
                'surface' => '会社',
                'base' => '会社',
                'partOfSpeech' => '名詞-普通名詞-一般',
            ]], $sentenceTokens]);
        $deck = Deck::factory()->create();
        $cardId = strtolower((string) Str::ulid());
        $data = CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: '会社があります。',
            backText: 'There is a company.',
            promptJson: ['cueText' => '会社があります。'],
            answerJson: ['expression' => '会社', 'expressionReading' => '会社[かいしゃ]', 'sentenceJp' => '会社があります。'],
            id: $cardId,
        );

        $first = app(CreateCardAction::class)->handle($data);
        $second = app(CreateCardAction::class)->handle($data);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $cardId,
            'concept_id' => 'n5-vocab-1198550-2120ff50',
            'match_method' => 'exact',
            'match_source' => 'creation',
            'classifier_version' => 'jlpt-n5-n4-rules-v5',
        ]);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $cardId,
            'concept_id' => 'n5-grammar-arimasu-existence-inanimate',
            'match_method' => 'classifier',
            'match_source' => 'creation',
            'classifier_version' => 'jlpt-n5-n4-rules-v5',
        ]);
        $this->assertSame(
            DB::table('card_learning_concepts')->where('card_id', $cardId)->count(),
            DB::table('card_learning_concepts')->where('card_id', $cardId)->distinct()->count('concept_id'),
        );
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_matches_inflected_n5_vocabulary_tokens_inside_a_sentence(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['新しい本を読みました。'])
            ->andReturn([[
                ['surface' => '新しい', 'base' => '新しい'],
                ['surface' => '本', 'base' => '本'],
                ['surface' => 'を', 'base' => 'を'],
                ['surface' => '読み', 'base' => '読む'],
                ['surface' => 'ました', 'base' => 'ます'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '新しい本を読みました。',
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
                'match_source' => 'creation',
                'classifier_version' => 'jlpt-n5-n4-rules-v5',
            ]);
        }
    }
}
