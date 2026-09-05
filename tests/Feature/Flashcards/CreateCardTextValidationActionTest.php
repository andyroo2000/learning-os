<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateCardTextValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_front_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '   ',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: '   ',
            ),
        );
    }
}
