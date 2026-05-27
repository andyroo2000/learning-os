<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CreateCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card_for_a_deck(): void
    {
        $deck = Deck::factory()->create();

        $card = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $this->assertTrue(Str::isUlid($card->id));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $card = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $this->assertSame($id, $card->id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = Deck::factory()->create();

        $card = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: "  {$deck->id}  ",
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('ciao', $card->front_text);
        $this->assertSame('hello', $card->back_text);
    }

    public function test_it_rejects_invalid_deck_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: 'not-a-ulid',
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_missing_deck(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck does not exist.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: strtolower((string) Str::ulid()),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
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
                deckId: $deck->id,
                frontText: 'ciao',
                backText: '   ',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: 'not-a-ulid',
            ),
        );
    }
}
