<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateDeckAction;
use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
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

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($deck->user_id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('deck', $entry->resource_type);
        $this->assertSame($deck->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame([
            'id' => $deck->id,
            'course_id' => null,
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
            'is_manual_study_deck' => false,
            'created_at' => $updatedDeck->created_at?->toJSON(),
            'updated_at' => $updatedDeck->updated_at?->toJSON(),
            'deleted_at' => null,
        ], $entry->payload);
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
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_preserves_manual_study_deck_flag_when_updating_metadata(): void
    {
        $deck = $this->deckFor($this->signIn(), [
            'is_manual_study_deck' => true,
        ]);

        $result = app(UpdateDeckAction::class)->handle(
            $deck,
            UpdateDeckData::fromInput(
                name: 'Manual Study Cards',
                description: 'Updated description.',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertTrue($result->deck->is_manual_study_deck);
        $this->assertTrue($deck->refresh()->is_manual_study_deck);
        $this->assertTrue(SyncFeedEntry::query()->sole()->payload['is_manual_study_deck']);
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
        $this->assertDatabaseCount('sync_feed_entries', 1);
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
        $this->assertDatabaseCount('sync_feed_entries', 0);
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
