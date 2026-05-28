<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachMediaToCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id)
            ->assertJsonPath('data.media_assets.0.url', 'https://cdn.example.test/uploads/example.jpg')
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
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.media_assets');

        $this->assertDatabaseCount('card_media', 1);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => 'not-a-ulid',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_missing_media_asset(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => strtolower((string) Str::ulid()),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_missing_card(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->postJson('/api/cards/'.strtolower((string) Str::ulid()).'/media-assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }
}
