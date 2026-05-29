<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteMediaAssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_media_asset_and_its_card_attachments(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->deleteJson("/api/media-assets/{$mediaAsset->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('media_assets', [
            'id' => $mediaAsset->id,
        ]);
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
        $this->signIn();

        $response = $this->deleteJson('/api/media-assets/'.strtolower((string) Str::ulid()));

        $response->assertNoContent();
    }

    public function test_it_is_idempotent_when_media_asset_id_is_malformed(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/media-assets/not-a-valid-id');

        $response->assertNoContent();
    }

    public function test_it_does_not_delete_another_users_media_asset(): void
    {
        $this->signIn();
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->deleteJson("/api/media-assets/{$mediaAsset->id}");

        $response->assertNoContent();

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->deleteJson("/api/media-assets/{$mediaAsset->id}");

        $response->assertUnauthorized();

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
        ]);
    }
}
