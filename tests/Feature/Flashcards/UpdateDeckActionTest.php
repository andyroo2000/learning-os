<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateDeckAction;
use App\Domain\Flashcards\Data\UpdateDeckData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_deck_metadata(): void
    {
        $deck = $this->deckFor($this->signIn());

        $updatedDeck = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: 'Italian Travel',
                description: 'Phrases for airport and train station practice.',
            ),
        );

        $this->assertSame($deck->id, $updatedDeck->id);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $deck->user_id,
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = $this->deckFor($this->signIn());

        $updatedDeck = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: '  Italian Travel  ',
                description: '  Phrases for airport and train station practice.  ',
            ),
        );

        $this->assertSame('Italian Travel', $updatedDeck->name);
        $this->assertSame('Phrases for airport and train station practice.', $updatedDeck->description);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $deck = $this->deckFor($this->signIn());

        $updatedDeck = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: 'Italian Travel',
                description: '   ',
            ),
        );

        $this->assertNull($updatedDeck->description);
    }

    public function test_it_rejects_blank_name(): void
    {
        $deck = $this->deckFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck name is required.');

        app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: '   ',
                description: null,
            ),
        );
    }
}
