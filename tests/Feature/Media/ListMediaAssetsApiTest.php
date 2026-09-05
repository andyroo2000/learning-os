<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_media_assets_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/first.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/first.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'first.jpg',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        $secondMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/second.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 234_567,
                'checksum_sha256' => str_repeat('b', 64),
                'original_filename' => 'second.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        MediaAsset::factory()
            ->for($otherUser)
            ->create([
                'path' => 'uploads/hidden.jpg',
            ]);

        $response = $this->getJson('/api/media-assets');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondMediaAsset->id)
            ->assertJsonPath('data.1.id', $firstMediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/second.jpg')
            ->assertJsonPath('data.0.content_url', "/api/media-assets/{$secondMediaAsset->id}/content")
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.0.size_bytes', 234_567)
            ->assertJsonPath('data.0.checksum_sha256', str_repeat('b', 64))
            ->assertJsonPath('data.0.original_filename', 'second.jpg')
            ->assertJsonPath('data.0.created_at', $secondMediaAsset->created_at?->toJSON())
            ->assertJsonPath('data.0.updated_at', $secondMediaAsset->updated_at?->toJSON())
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.url_expires_at')
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
                'links',
                'meta',
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_media_assets(): void
    {
        $this->signIn();
        MediaAsset::factory()->for(User::factory()->create())->create();

        $response = $this->getJson('/api/media-assets');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/media-assets');

        $response->assertUnauthorized();
    }
}
