<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\CannotAttachMediaToCard;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachMediaToCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card_from_raw_ids(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromInput(
                cardId: $card->id,
                mediaAssetId: $mediaAsset->id,
            ),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertTrue($updatedCard->mediaAssets->contains($mediaAsset));

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromInput(
                cardId: $card->id,
                mediaAssetId: $mediaAsset->id,
            ),
        );

        $this->assertDatabaseCount('card_media', 1);
    }

    public function test_it_trims_inputs(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromInput(
                cardId: "  {$card->id}  ",
                mediaAssetId: "  {$mediaAsset->id}  ",
            ),
        );

        $this->assertTrue($updatedCard->mediaAssets->contains($mediaAsset));
    }

    public function test_it_can_use_preloaded_models(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels(
                card: $card,
                mediaAsset: $mediaAsset,
            ),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertTrue($updatedCard->mediaAssets->contains($mediaAsset));
    }

    public function test_it_rejects_missing_card(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $this->expectException(CannotAttachMediaToCard::class);
        $this->expectExceptionMessage('Card does not exist.');

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromInput(
                cardId: strtolower((string) Str::ulid()),
                mediaAssetId: $mediaAsset->id,
            ),
        );
    }

    public function test_it_rejects_missing_media_asset(): void
    {
        $card = Card::factory()->create();

        $this->expectException(CannotAttachMediaToCard::class);
        $this->expectExceptionMessage('Media asset does not exist.');

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromInput(
                cardId: $card->id,
                mediaAssetId: strtolower((string) Str::ulid()),
            ),
        );
    }
}
