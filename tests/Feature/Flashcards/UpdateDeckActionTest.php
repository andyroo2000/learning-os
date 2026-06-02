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

        $result = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: 'Italian Travel',
                description: 'Phrases for airport and train station practice.',
            ),
        );
        $updatedDeck = $result->deck;

        $this->assertTrue($result->wasUpdated);
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
        $deck = $this->deckFor($this->signIn(), [
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $result = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: '  Italian Travel  ',
                description: '  Phrases for airport and train station practice.  ',
            ),
        );
        $updatedDeck = $result->deck;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('Italian Travel', $updatedDeck->name);
        $this->assertSame('Phrases for airport and train station practice.', $updatedDeck->description);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $deck = $this->deckFor($this->signIn());

        $result = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: 'Italian Travel',
                description: '   ',
            ),
        );
        $updatedDeck = $result->deck;

        $this->assertTrue($result->wasUpdated);
        $this->assertNull($updatedDeck->description);
    }

    public function test_it_marks_unchanged_when_normalized_metadata_matches_the_existing_deck(): void
    {
        $deck = $this->deckFor($this->signIn(), [
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $result = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: '  Italian Basics  ',
                description: '  Foundational Italian review cards.  ',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($deck->id, $result->deck->id);
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
