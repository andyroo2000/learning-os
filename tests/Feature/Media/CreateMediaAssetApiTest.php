<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\TestCase;

class CreateMediaAssetApiTest extends TestCase
{
    use AssertsMediaAssetSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_creates_a_media_asset(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.content_url', '/api/media-assets/'.$response->json('data.id').'/content')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.size_bytes', 123_456)
            ->assertJsonPath('data.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.path')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'url',
                    'content_url',
                    'mime_type',
                    'size_bytes',
                    'checksum_sha256',
                    'original_filename',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('media_assets', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $mediaAsset = MediaAsset::query()->findOrFail($response->json('data.id'));

        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset, SyncFeedOperation::Create);
    }

    public function test_it_serializes_the_maximum_size_without_precision_loss(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/max-size.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => MediaAsset::MAX_JSON_SAFE_SIZE_BYTES,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.size_bytes', MediaAsset::MAX_JSON_SAFE_SIZE_BYTES);
    }

    public function test_it_casts_validated_size_bytes_before_creating_media(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/numeric-string-size.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => '123456',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.size_bytes', 123_456);

        $this->assertDatabaseHas('media_assets', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'size_bytes' => 123_456,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('media_assets', 0);
    }
}
