<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetIdConflictApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
            ]);

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/different.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertConflict()
            // Assert literal strings so response-contract changes fail loudly.
            ->assertJsonPath('message', 'Media asset ID already exists with different metadata.')
            ->assertJsonPath('reason', 'media_asset_id_conflict');

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_hides_client_provided_ulid_conflicts_for_other_users(): void
    {
        $this->signIn();
        $mediaAsset = MediaAsset::factory()->create([
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/different.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_hides_idempotent_retries_for_other_users_assets(): void
    {
        $mediaAsset = MediaAsset::factory()
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'public_url' => null,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => null,
                'original_filename' => null,
            ]);

        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseCount('media_assets', 1);
    }
}
