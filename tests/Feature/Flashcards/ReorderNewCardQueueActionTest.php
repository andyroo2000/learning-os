<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ReorderNewCardQueueAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ReorderNewCardQueueActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_reorders_owned_new_cards_and_records_sync_entries(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $thirdCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $cards = app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [$thirdCard->id, strtoupper($firstCard->id), $secondCard->id],
        );

        $this->assertSame([$thirdCard->id, $firstCard->id, $secondCard->id], $cards->pluck('id')->all());
        $this->assertDatabaseHas('cards', [
            'id' => $thirdCard->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $firstCard->id,
            'new_queue_position' => 2,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $secondCard->id,
            'new_queue_position' => 3,
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 3);

        $entries = collect([
            $this->assertCardSyncPayloadRecorded($firstCard->refresh(), SyncFeedOperation::Update),
            $this->assertCardSyncPayloadRecorded($secondCard->refresh(), SyncFeedOperation::Update),
            $this->assertCardSyncPayloadRecorded($thirdCard->refresh(), SyncFeedOperation::Update),
        ]);

        $this->assertSame([
            $firstCard->id => 2,
            $secondCard->id => 3,
            $thirdCard->id => 1,
        ], $entries->mapWithKeys(fn (SyncFeedEntry $entry): array => [
            $entry->resource_id => $entry->payload['new_queue_position'],
        ])->all());
    }

    public function test_it_repairs_null_queue_positions_when_reordering_legacy_new_cards(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $legacyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $queuedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 8,
        ]);

        app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [$legacyCard->id, $queuedCard->id],
        );

        $this->assertDatabaseHas('cards', [
            'id' => $legacyCard->id,
            'new_queue_position' => 2,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $queuedCard->id,
            'new_queue_position' => 9,
        ]);
    }

    public function test_it_repairs_multiple_legacy_null_positions_deterministically(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstLegacyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh31',
        ]);
        $positionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh32',
            'new_queue_position' => 4,
        ]);
        $secondLegacyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh33',
        ]);

        $cards = app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [$secondLegacyCard->id, $positionedCard->id, $firstLegacyCard->id],
        );

        $this->assertSame(
            [$secondLegacyCard->id, $positionedCard->id, $firstLegacyCard->id],
            $cards->pluck('id')->all(),
        );
        $this->assertSame(4, $secondLegacyCard->refresh()->new_queue_position);
        $this->assertSame(5, $positionedCard->refresh()->new_queue_position);
        $this->assertSame(6, $firstLegacyCard->refresh()->new_queue_position);
        $this->assertDatabaseCount('sync_feed_entries', 3);
    }

    public function test_it_is_idempotent_when_order_is_unchanged(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->assertNotNull($firstCard->updated_at);
        $this->assertNotNull($secondCard->updated_at);
        $firstUpdatedAt = $firstCard->updated_at->toJSON();
        $secondUpdatedAt = $secondCard->updated_at->toJSON();

        $cards = app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [$firstCard->id, $secondCard->id],
        );

        $this->assertSame([$firstCard->id, $secondCard->id], $cards->pluck('id')->all());
        $this->assertSame(1, $firstCard->refresh()->new_queue_position);
        $this->assertSame(2, $secondCard->refresh()->new_queue_position);
        $this->assertNotNull($firstCard->updated_at);
        $this->assertNotNull($secondCard->updated_at);
        $this->assertSame($firstUpdatedAt, $firstCard->updated_at->toJSON());
        $this->assertSame($secondUpdatedAt, $secondCard->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_reorders_uppercase_imported_card_ids_after_canonical_normalization(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01KTT2Q9Z5VFPXSQGC3MWRDH41',
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01KTT2Q9Z5VFPXSQGC3MWRDH42',
            'new_queue_position' => 2,
        ]);

        $cards = app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [strtolower($secondCard->id), strtolower($firstCard->id)],
        );

        $this->assertSame([$secondCard->id, $firstCard->id], $cards->pluck('id')->all());
        $this->assertSame(1, $secondCard->refresh()->new_queue_position);
        $this->assertSame(2, $firstCard->refresh()->new_queue_position);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_duplicate_card_ids(): void
    {
        $cardId = strtolower((string) str()->ulid());

        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('card_ids must not contain duplicates.');

        app(ReorderNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            cardIds: [$cardId, strtoupper($cardId)],
        );
    }

    public function test_it_rejects_more_than_the_convolab_queue_batch_limit(): void
    {
        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('card_ids must include between 1 and 500 cards.');

        app(ReorderNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            cardIds: array_fill(0, 501, strtolower((string) str()->ulid())),
        );
    }

    public function test_it_rejects_malformed_card_ids_for_direct_callers(): void
    {
        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('Each card_id must be a valid ULID.');

        app(ReorderNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            cardIds: ['not-a-ulid'],
        );
    }

    public function test_it_rejects_non_active_or_unowned_cards(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'new_queue_position' => 2,
        ]);

        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('Every reordered card must be an active new card owned by the user.');

        app(ReorderNewCardQueueAction::class)->handle(
            userId: $user->id,
            cardIds: [$newCard->id, $reviewCard->id],
        );
    }

    public function test_it_rolls_back_when_sync_recording_fails(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $reorderNewCardQueue = new ReorderNewCardQueueAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $reorderNewCardQueue->handle(
                userId: $user->id,
                cardIds: [$secondCard->id, $firstCard->id],
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('cards', [
                'id' => $firstCard->id,
                'new_queue_position' => 1,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $secondCard->id,
                'new_queue_position' => 2,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
