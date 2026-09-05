<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\MediaAssetRateLimiter;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Media\Concerns\UsesMediaRateLimitOverrides;
use Tests\TestCase;

class CreateMediaAssetIdempotencyApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesMediaRateLimitOverrides;

    public function test_it_returns_existing_media_asset_for_idempotent_retries(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());
        $publicUrl = 'https://cdn.example.test/uploads/example.jpg';
        $payload = [
            'id' => $id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => $publicUrl,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ];

        $firstResponse = $this->postJson('/api/media-assets', $payload);
        $secondResponse = $this->postJson('/api/media-assets', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.url', $publicUrl)
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.size_bytes', 123_456)
            ->assertJsonPath('data.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.path');
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.url', $publicUrl)
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.size_bytes', 123_456)
            ->assertJsonPath('data.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.path');

        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_create_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $ids = [
            strtolower((string) Str::ulid()),
            strtolower((string) Str::ulid()),
            strtolower((string) Str::ulid()),
        ];
        $otherUser = User::factory()->create();
        $otherId = strtolower((string) Str::ulid());

        $this->withMediaRateLimitOverride(
            MediaAssetRateLimiter::CREATE_NAME,
            [$user->id, $otherUser->id],
            function () use ($ids, $otherId, $otherUser, $user): void {
                foreach ([0, 1] as $index) {
                    $this
                        ->postJson('/api/media-assets', $this->mediaAssetCreatePayload($ids[$index], "uploads/media-{$index}.jpg"))
                        ->assertCreated();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson('/api/media-assets', $this->mediaAssetCreatePayload($otherId, 'uploads/other-media.jpg'))
                    ->assertCreated();

                $this->signIn($user);

                $this
                    ->postJson('/api/media-assets', $this->mediaAssetCreatePayload($ids[2], 'uploads/blocked-media.jpg'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson('/api/media-assets')
                    ->assertOk()
                    ->assertJsonCount(2, 'data');

                $this->assertSame(2, MediaAsset::query()->where('user_id', $user->id)->count());
                $this->assertSame(1, MediaAsset::query()->where('user_id', $otherUser->id)->count());
                $this->assertDatabaseMissing('media_assets', [
                    'id' => $ids[2],
                    'user_id' => $user->id,
                ]);
                $this->assertDatabaseMissing('sync_feed_entries', [
                    'resource_type' => MediaAssetSyncPayload::RESOURCE_TYPE,
                    'resource_id' => $ids[2],
                ]);
            },
        );
    }

    public function test_it_normalizes_checksum_before_matching_idempotent_retries(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $checksum = str_repeat('aB', 32);
        $payload = [
            'id' => $id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => $checksum,
            'original_filename' => null,
        ];

        // The model mutator lowercases persisted checksums, so idempotency compares
        // against the same normalized form the API stores for client-created assets.
        MediaAsset::factory()
            ->for($user)
            ->create($payload);

        $response = $this->postJson('/api/media-assets', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.checksum_sha256', strtolower($checksum));

        $this->assertDatabaseCount('media_assets', 1);
    }

    /**
     * @return array{id: string, disk: string, path: string, mime_type: string, size_bytes: int}
     */
    private function mediaAssetCreatePayload(string $id, string $path): array
    {
        return [
            'id' => $id,
            'disk' => 'media',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ];
    }
}
