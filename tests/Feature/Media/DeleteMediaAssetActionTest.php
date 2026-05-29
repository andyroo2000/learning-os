<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteMediaAssetActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_media_asset(): void
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
    }

    public function test_it_removes_card_attachments_when_deleting_a_media_asset(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: $mediaAsset->id,
        ));

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_is_idempotent_when_media_asset_is_missing(): void
    {
        $user = User::factory()->create();

        app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
            userId: $user->id,
            mediaAssetId: (string) Str::ulid(),
        ));

        $this->assertDatabaseCount('media_assets', 0);
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
    }
}
