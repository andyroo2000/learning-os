<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card(): void
    {
        $card = Card::factory()->create();
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
    }

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $mediaAsset),
        );

        $this->assertDatabaseCount('card_media', 1);
    }
}
