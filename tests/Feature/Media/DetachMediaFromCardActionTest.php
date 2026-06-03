<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DetachMediaFromCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detaches_media_from_a_card(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);
        $pivot = $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot;

        $updatedCard = app(DetachMediaFromCardAction::class)->handle(
            DetachMediaFromCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertFalse($updatedCard->mediaAssets->contains($mediaAsset));

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertTrue($card->fresh()->updated_at->isAfter($timestamp));

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame('media', $entry->domain);
        $this->assertSame('card_media', $entry->resource_type);
        $this->assertSame("{$card->id}:{$mediaAsset->id}", $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame([
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
            'created_at' => $pivot?->created_at?->toJSON(),
            'updated_at' => $pivot?->updated_at?->toJSON(),
        ], $entry->payload);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();
        $detachMedia = new DetachMediaFromCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        $card->mediaAssets()->attach($mediaAsset->id);

        try {
            $detachMedia->handle(DetachMediaFromCardData::fromModels($card, $mediaAsset));

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'updated_at' => $timestamp,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_is_idempotent_when_attachment_is_already_missing(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(DetachMediaFromCardAction::class)->handle(
            DetachMediaFromCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertCount(0, $updatedCard->mediaAssets);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
