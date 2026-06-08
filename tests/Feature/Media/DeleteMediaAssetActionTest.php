<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DeleteMediaAssetActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_unattached_media_asset_with_one_asset_tombstone(): void
    {
        $user = User::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: $mediaAsset->id,
        ));

        $this->assertDatabaseMissing('media_assets', [
            'id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $user = User::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $deleteMediaAsset = new DeleteMediaAssetAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $deleteMediaAsset->handle(DeleteMediaAssetData::fromInput(
                userId: $user->id,
                mediaAssetId: $mediaAsset->id,
            ));

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('media_assets', ['id' => $mediaAsset->id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_removes_card_attachments_when_deleting_a_media_asset(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $firstDeck = Deck::factory()->for($course)->for($user)->create();
        $secondDeck = Deck::factory()->for($course)->for($user)->create();
        $firstCard = Card::factory()->for($firstDeck)->create();
        $secondCard = Card::factory()->for($secondDeck)->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $firstCard->mediaAssets()->attach($mediaAsset->id);
        $secondCard->mediaAssets()->attach($mediaAsset->id);

        $pivotsByCardId = collect([$firstCard, $secondCard])
            ->mapWithKeys(fn ($card): array => [
                $card->id => $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot,
            ]);

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: $mediaAsset->id,
        ));

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $firstCard->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseMissing('card_media', [
            'card_id' => $secondCard->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $firstCard->id,
        ]);
        $this->assertDatabaseHas('cards', ['id' => $secondCard->id]);

        $cardsByReplayOrder = collect([$firstCard, $secondCard])
            ->sortBy('id')
            ->values();

        $this->assertDatabaseCount('sync_feed_entries', 3);

        $cardEntries = $cardsByReplayOrder->map(function (Card $card) use ($course, $mediaAsset, $pivotsByCardId): SyncFeedEntry {
            $pivot = $pivotsByCardId->get($card->id);

            return $this->assertCardMediaSyncPayloadRecorded(
                userId: $mediaAsset->user_id,
                card: $card,
                mediaAsset: $mediaAsset,
                courseId: $course->id,
                createdAt: $pivot?->created_at,
                updatedAt: $pivot?->updated_at,
            );
        });

        $assetEntry = $this->assertMediaAssetSyncPayloadRecorded($mediaAsset);
        $this->assertLessThan($cardEntries->get(1)->checkpoint, $cardEntries->get(0)->checkpoint);
        $this->assertLessThan($assetEntry->checkpoint, $cardEntries->get(1)->checkpoint);
    }

    public function test_it_normalizes_media_asset_id_before_deleting(): void
    {
        $user = User::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: '  '.strtoupper($mediaAsset->id).'  ',
        ));

        $this->assertDatabaseMissing('media_assets', [
            'id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset);
    }

    public function test_it_rolls_back_media_asset_delete_when_card_media_feed_recording_fails(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $card->mediaAssets()->attach($mediaAsset->id);
        $deleteMediaAsset = new DeleteMediaAssetAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    if ($data->resourceType === CardMediaSyncPayload::RESOURCE_TYPE) {
                        throw new RuntimeException('Sync feed failed.');
                    }

                    return parent::handle($data);
                }
            },
        );

        try {
            $deleteMediaAsset->handle(DeleteMediaAssetData::fromInput(
                userId: $user->id,
                mediaAssetId: $mediaAsset->id,
            ));

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('media_assets', ['id' => $mediaAsset->id]);
            $this->assertDatabaseHas('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_skips_card_media_tombstones_for_cross_owner_pivots(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        // This cannot happen through the attach API, but imported/corrupt rows should not leak into the owner's feed.
        $otherCard->mediaAssets()->attach($mediaAsset->id);

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: $mediaAsset->id,
        ));

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $otherCard->id,
            'media_asset_id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset);
    }

    public function test_it_is_idempotent_when_media_asset_is_missing(): void
    {
        $user = User::factory()->create();

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: strtolower((string) Str::ulid()),
        ));

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_does_not_delete_another_users_media_asset(): void
    {
        $user = User::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: $mediaAsset->id,
        ));

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    private function assertMediaAssetSyncPayloadRecorded(MediaAsset $mediaAsset): SyncFeedEntry
    {
        $entry = SyncFeedEntry::query()
            ->where('domain', MediaAssetSyncPayload::DOMAIN)
            ->where('resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $mediaAsset->id)
            ->where('operation', SyncFeedOperation::Delete->value)
            ->sole();

        $this->assertSame($mediaAsset->user_id, $entry->user_id);
        $this->assertEquals(MediaAssetSyncPayload::fromMediaAsset($mediaAsset), $entry->payload);

        return $entry;
    }

    private function assertCardMediaSyncPayloadRecorded(
        int|string $userId,
        Card $card,
        MediaAsset $mediaAsset,
        ?string $courseId,
        Carbon|string|null $createdAt,
        Carbon|string|null $updatedAt,
    ): SyncFeedEntry {
        $entry = SyncFeedEntry::query()
            ->where('domain', CardMediaSyncPayload::DOMAIN)
            ->where('resource_type', CardMediaSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', CardMediaSyncPayload::resourceId($card->id, $mediaAsset->id))
            ->where('operation', SyncFeedOperation::Delete->value)
            ->sole();

        $this->assertSame($userId, $entry->user_id);
        $this->assertEquals(CardMediaSyncPayload::fromPivot(
            cardId: $card->id,
            mediaAssetId: $mediaAsset->id,
            deckId: $card->deck_id,
            courseId: $courseId,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        ), $entry->payload);

        return $entry;
    }
}
