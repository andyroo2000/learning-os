<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => strtoupper($mediaAsset->id),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonStructure(['data' => ['media_assets' => [['url']]]])
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id)
            ->assertJsonPath('data.media_assets.0.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.media_assets.0.content_url', "/api/media-assets/{$mediaAsset->id}/content")
            ->assertJsonPath('data.media_assets.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.media_assets.0.size_bytes', 123_456)
            ->assertJsonPath('data.media_assets.0.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.media_assets.0.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.media_assets.0.disk')
            ->assertJsonMissingPath('data.media_assets.0.path')
            ->assertJsonMissingPath('data.media_assets.0.url_expires_at');

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);
        $this->assertNotNull($card->updated_at);
        $originalUpdatedAt = $card->updated_at->toJSON();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.media_assets')
            ->assertJsonStructure(['data' => ['media_assets' => [['url']]]])
            ->assertJsonPath('data.media_assets.0.url', null)
            ->assertJsonPath('data.media_assets.0.content_url', "/api/media-assets/{$mediaAsset->id}/content");

        $card->refresh();

        $this->assertNotNull($card->updated_at);
        $this->assertSame($originalUpdatedAt, $card->updated_at->toJSON());
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_preserves_existing_attachments_when_adding_another_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $firstMediaAsset = MediaAsset::factory()->for($user)->create();
        $secondMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.jpg')
            ->create();

        $card->mediaAssets()->attach($firstMediaAsset->id);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $secondMediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.media_assets')
            ->assertJsonFragment(['id' => $firstMediaAsset->id])
            ->assertJsonFragment(['id' => $secondMediaAsset->id])
            ->assertJsonFragment(['url' => 'https://cdn.example.test/uploads/second.jpg']);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $firstMediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $secondMediaAsset->id,
        ]);
        $this->assertDatabaseCount('card_media', 2);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_media', 0);
    }
}
