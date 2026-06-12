<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Media\AssertsCardMediaSyncFeedEntries;
use Tests\TestCase;

class DetachMediaFromCardActionTest extends TestCase
{
    use AssertsCardMediaSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_detaches_media_from_a_card(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = $this->mediaAssetForCardOwner($card);

        $card->mediaAssets()->attach($mediaAsset->id);
        $pivot = $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot;
        $this->assertNotNull($pivot);

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
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $this->assertCardMediaSyncPayloadRecorded(
            userId: $card->ownerUserId(),
            card: $card,
            mediaAsset: $mediaAsset,
            operation: SyncFeedOperation::Delete,
            deckId: $card->deck_id,
            courseId: $course->id,
            createdAt: $pivot->created_at,
            updatedAt: $pivot->updated_at,
        );
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = $this->mediaAssetForCardOwner($card);
        $detachMedia = new DetachMediaFromCardAction(
            recordCardMediaSyncFeedEntry: new RecordCardMediaSyncFeedEntryAction(
                recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
                {
                    public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                    {
                        throw new RuntimeException('Sync feed failed.');
                    }
                },
            ),
        );

        $card->mediaAssets()->attach($mediaAsset->id);

        // Use try/catch so post-exception database assertions still run.
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
        $mediaAsset = $this->mediaAssetForCardOwner($card);
        $this->assertNotNull($card->updated_at);
        $originalUpdatedAt = $card->updated_at->toJSON();

        $updatedCard = app(DetachMediaFromCardAction::class)->handle(
            DetachMediaFromCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertCount(0, $updatedCard->mediaAssets);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertNotNull($card->refresh()->updated_at);
        $this->assertSame($originalUpdatedAt, $card->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_media_assets_owned_by_another_user(): void
    {
        $card = $this->cardFor(User::factory()->create());
        $mediaAsset = MediaAsset::factory()
            ->for(User::factory()->create())
            ->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        // Use try/catch so post-exception database assertions still run.
        try {
            app(DetachMediaFromCardAction::class)->handle(
                DetachMediaFromCardData::fromModels($card, $mediaAsset),
            );

            $this->fail('Expected owner mismatch was not thrown.');
        } catch (MediaOwnershipException $exception) {
            $this->assertSame(
                "Card {$card->id} owner {$card->ownerUserId()} and media asset {$mediaAsset->id} owner {$mediaAsset->user_id} differ.",
                $exception->getMessage(),
            );
            $this->assertDatabaseHas('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
