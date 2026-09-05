<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardAmbiguityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_fan_an_ambiguous_vocabulary_reading_out_to_homophones(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'あつい',
                backText: 'hot or thick',
            ),
        );

        foreach ([
            'n5-vocab-1343460-7b4aecdf',
            'n5-vocab-1467720-a379d413',
            'n5-vocab-1275320-9949d874',
        ] as $ambiguousConceptId) {
            $this->assertDatabaseMissing('card_learning_concepts', [
                'card_id' => $result->card->id,
                'concept_id' => $ambiguousConceptId,
            ]);
        }
    }

    public function test_it_does_not_infer_ambiguous_vocabulary_tokens_inside_a_sentence(): void
    {
        $this->mock(JapaneseTokenizer::class)
            ->shouldReceive('tokenize')
            ->once()
            ->with(['今日はあついです。'])
            ->andReturn([[
                ['surface' => '今日', 'base' => '今日'],
                ['surface' => 'は', 'base' => 'は'],
                ['surface' => 'あつい', 'base' => 'あつい'],
                ['surface' => 'です', 'base' => 'です'],
            ]]);
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '今日はあついです。',
                backText: 'It is hot today.',
            ),
        );

        foreach ([
            'n5-vocab-1343460-7b4aecdf',
            'n5-vocab-1467720-a379d413',
            'n5-vocab-1275320-9949d874',
        ] as $ambiguousConceptId) {
            $this->assertDatabaseMissing('card_learning_concepts', [
                'card_id' => $result->card->id,
                'concept_id' => $ambiguousConceptId,
            ]);
        }
    }
}
