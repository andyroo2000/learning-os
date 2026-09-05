<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Str;
use RuntimeException;

class ReviewCardRollbackActionTest extends ReviewCardActionTestCase
{
    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $reviewCard = new ReviewCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $id,
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('card_review_events', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rolls_back_when_card_study_state_sync_recording_fails(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $recordSyncFeedEntry = new class extends RecordSyncFeedEntryAction
        {
            public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
            {
                if ($data->resourceType === 'card') {
                    throw new RuntimeException('Card sync feed failed.');
                }

                return parent::handle($data);
            }
        };
        $reviewCard = new ReviewCardAction(
            recordSyncFeedEntry: $recordSyncFeedEntry,
        );

        try {
            $reviewCard->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: 'easy',
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $id,
                ),
            );

            $this->fail('Expected card sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Card sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('card_review_events', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
            $this->assertSame(CardStudyStatus::New, $card->refresh()->study_status);
            $this->assertNull($card->last_reviewed_at);
        }
    }
}
