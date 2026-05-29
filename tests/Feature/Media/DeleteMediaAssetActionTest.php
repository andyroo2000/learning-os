<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteMediaAssetActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        app(DeleteMediaAssetAction::class)->handle($mediaAsset);

        $this->assertDatabaseMissing('media_assets', [
            'id' => $mediaAsset->id,
        ]);
    }

    public function test_it_removes_card_attachments_when_deleting_a_media_asset(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(DeleteMediaAssetAction::class)->handle($mediaAsset);

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
        ]);
    }
}
