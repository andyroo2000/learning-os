<?php

namespace Tests\Feature\Study;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportMediaAssetsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/media-assets')->assertUnauthorized();
    }

    public function test_convolab_media_export_alias_requires_authentication(): void
    {
        $this->getJson('/api/study/export/media')->assertUnauthorized();
    }

    public function test_index_returns_media_assets_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/first.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/first.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'first.jpg',
            ]);
        $secondAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.mp3')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/second.mp3',
                'mime_type' => 'audio/mpeg',
                'size_bytes' => 234_567,
                'checksum_sha256' => str_repeat('b', 64),
                'original_filename' => 'second.mp3',
            ]);
        $otherAsset = MediaAsset::factory()->for($otherUser)->create();

        $this->getJson('/api/study/export/media-assets')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/first.jpg')
            ->assertJsonPath('data.0.content_url', "/api/media-assets/{$firstAsset->id}/content")
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.0.size_bytes', 123_456)
            ->assertJsonPath('data.0.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.0.original_filename', 'first.jpg')
            ->assertJsonPath('data.1.id', $secondAsset->id)
            ->assertJsonPath('data.1.url', 'https://cdn.example.test/uploads/second.mp3')
            ->assertJsonPath('data.1.content_url', "/api/media-assets/{$secondAsset->id}/content")
            ->assertJsonPath('data.1.mime_type', 'audio/mpeg')
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissing([
                'id' => $otherAsset->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
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
                ],
            ]);
    }

    public function test_convolab_media_export_alias_returns_media_assets_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $asset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/alias.jpg')
            ->create([
                'mime_type' => 'image/jpeg',
                'original_filename' => 'alias.jpg',
            ]);
        MediaAsset::factory()->for(User::factory()->create())->create();

        $this->getJson('/api/study/export/media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $asset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/alias.jpg')
            ->assertJsonPath('data.0.original_filename', 'alias.jpg');
    }
}
