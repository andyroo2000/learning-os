<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowMediaAssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_owned_media_asset(): void
    {
        $user = $this->signIn();
        $mediaAsset = $this->mediaAssetFor($user, [
            'mime_type' => 'image/jpeg',
            'size_bytes' => 234_567,
            'checksum_sha256' => str_repeat('b', 64),
            'original_filename' => 'front.jpg',
            'created_at' => now()->subMinute()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
        ]);

        // public_url is intentionally not fillable, matching the production write path.
        $mediaAsset->public_url = 'https://cdn.example.test/uploads/front.jpg';
        $mediaAsset->save();

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $mediaAsset->id,
                    'url' => 'https://cdn.example.test/uploads/front.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 234_567,
                    'checksum_sha256' => str_repeat('b', 64),
                    'original_filename' => 'front.jpg',
                    'created_at' => $mediaAsset->created_at->toJSON(),
                    'updated_at' => $mediaAsset->updated_at->toJSON(),
                ],
            ]);
    }

    public function test_it_normalizes_media_asset_id_before_showing(): void
    {
        $user = $this->signIn();
        $mediaAsset = $this->mediaAssetFor($user);
        $routeId = rawurlencode('  '.strtoupper($mediaAsset->id).'  ');

        $response = $this->getJson("/api/media-assets/{$routeId}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $mediaAsset->id);
    }

    public function test_it_hides_another_users_media_asset(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $mediaAsset = $this->mediaAssetFor($otherUser);

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_media_asset(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets/'.(string) Str::ulid());

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_media_asset_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $mediaAsset = $this->mediaAssetFor(User::factory()->create());

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}");

        $response->assertUnauthorized();
    }
}
