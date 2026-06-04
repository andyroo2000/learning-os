<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class DeleteDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_deck_and_its_cards(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();

        $result = app(DeleteDeckAction::class)->handle($deck);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($deck, $result->deck);
        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
        $this->assertSoftDeleted('cards', [
            'id' => $firstCard->id,
        ]);
        $this->assertSoftDeleted('cards', [
            'id' => $secondCard->id,
        ]);

        $entries = SyncFeedEntry::query()
            ->orderBy('checkpoint')
            ->get();
        $deletedCards = Card::withTrashed()
            ->whereKey([$firstCard->id, $secondCard->id])
            ->orderBy('id')
            ->get()
            ->values();

        $this->assertCount(3, $entries);

        // Checkpoint order is the sync feed replay contract: child card tombstones come first.
        foreach ($deletedCards as $index => $card) {
            $entry = $entries[$index];

            $this->assertNotNull($card->deleted_at);
            $this->assertSame($deck->user_id, $entry->user_id);
            $this->assertSame('flashcards', $entry->domain);
            $this->assertSame('card', $entry->resource_type);
            $this->assertSame($card->id, $entry->resource_id);
            $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
            $this->assertSame([
                'id' => $card->id,
                'deck_id' => $deck->id,
                'course_id' => null,
                'front_text' => $card->front_text,
                'back_text' => $card->back_text,
                'study_status' => 'new',
                'new_queue_position' => $card->new_queue_position,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
                'created_at' => $card->created_at?->toJSON(),
                'updated_at' => $card->updated_at?->toJSON(),
                'deleted_at' => $card->deleted_at?->toJSON(),
            ], $entry->payload);
        }

        $entry = $entries->last();

        $this->assertSame($deck->user_id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('deck', $entry->resource_type);
        $this->assertSame($deck->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame([
            'id' => $deck->id,
            'course_id' => null,
            'name' => $deck->name,
            'description' => $deck->description,
            'created_at' => $deck->created_at?->toJSON(),
            'updated_at' => $deck->updated_at?->toJSON(),
            'deleted_at' => $deck->deleted_at?->toJSON(),
        ], $entry->payload);
    }

    public function test_it_soft_deletes_an_empty_deck(): void
    {
        $deck = Deck::factory()->create();

        $result = app(DeleteDeckAction::class)->handle($deck);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($deck, $result->deck);
        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->for($deck)->create();
        $deleteDeck = new DeleteDeckAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $deleteDeck->handle($deck);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rolls_back_when_deck_feed_recording_fails_after_card_entries(): void
    {
        $deck = Deck::factory()->create();
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $deleteDeck = new DeleteDeckAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    if ($data->resourceType === 'deck') {
                        throw new RuntimeException('Sync feed failed.');
                    }

                    return parent::handle($data);
                }
            },
        );

        try {
            $deleteDeck->handle($deck);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $firstCard->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $secondCard->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_no_ops_when_the_deck_is_already_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $deck->delete();
            $originalDeckDeletedAt = $deck->refresh()->deleted_at;
            $originalCardDeletedAt = $card->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteDeckAction::class)->handle($deck);

            $this->assertFalse($result->wasDeleted);
            $this->assertSame($deck, $result->deck);
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => $originalDeckDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => $originalCardDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_preserves_independently_deleted_card_timestamps(): void
    {
        $deck = Deck::factory()->create();
        $independentlyDeletedCard = Card::factory()->for($deck)->create();
        $activeCard = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $independentlyDeletedCard->delete();
            $originalDeletedAt = $independentlyDeletedCard->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteDeckAction::class)->handle($deck);

            $this->assertTrue($result->wasDeleted);
            $this->assertSame($deck, $result->deck);
            $this->assertSoftDeleted('cards', [
                'id' => $activeCard->id,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $independentlyDeletedCard->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
            // One tombstone for the newly cascade-deleted card, plus one for the deck.
            $this->assertDatabaseCount('sync_feed_entries', 2);
            $this->assertDatabaseMissing('sync_feed_entries', [
                'resource_type' => 'card',
                'resource_id' => $independentlyDeletedCard->id,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
