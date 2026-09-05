<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreateCardRollbackActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_back_client_provided_id_creates_when_feed_recording_fails(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $createCard = new CreateCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                    id: $id,
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('cards', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rolls_back_server_generated_id_creates_when_feed_recording_fails(): void
    {
        $deck = Deck::factory()->create();
        $createCard = new CreateCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseCount('cards', 0);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
