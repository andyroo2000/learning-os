<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateCardTypeValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_card_type_for_direct_callers(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must not be blank when provided.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: '   ',
            ),
        );
    }

    public function test_it_rejects_malformed_card_type_for_direct_callers(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must be one of: recognition, production, cloze.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: 'reverse',
            ),
        );
    }
}
