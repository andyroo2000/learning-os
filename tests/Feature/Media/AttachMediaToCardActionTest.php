<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AttachMediaToCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertTrue($updatedCard->mediaAssets->contains($mediaAsset));

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertTrue($card->fresh()->updated_at->isAfter($timestamp));

        $entry = SyncFeedEntry::query()->sole();
        $pivot = $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot;

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame('media', $entry->domain);
        $this->assertSame('card_media', $entry->resource_type);
        $this->assertSame("{$card->id}:{$mediaAsset->id}", $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
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
        $attachMedia = new AttachMediaToCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $attachMedia->handle(AttachMediaToCardData::fromModels($card, $mediaAsset));

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('card_media', [
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

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $mediaAsset),
        );

        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_sync_without_detaching_reports_no_updated_changes_without_pivot_attributes(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $changes = $card->mediaAssets()->syncWithoutDetaching([$mediaAsset->id]);

        $this->assertSame([], $changes['attached']);
        $this->assertSame([], $changes['updated']);
    }

    public function test_it_loads_media_assets_in_id_order(): void
    {
        $card = Card::factory()->create();
        $firstMediaAsset = MediaAsset::factory()->create();
        $secondMediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($secondMediaAsset->id);

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $firstMediaAsset),
        );
        $expectedMediaAssetIds = collect([$firstMediaAsset->id, $secondMediaAsset->id])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedMediaAssetIds, $updatedCard->mediaAssets->pluck('id')->all());
    }
}
