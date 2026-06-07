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

class DeleteMediaAssetApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesMediaRateLimitOverrides;

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

    public function test_it_normalizes_media_asset_id_before_deleting(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $routeId = rawurlencode('  '.strtoupper($mediaAsset->id).'  ');

        $response = $this->deleteJson("/api/media-assets/{$routeId}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('media_assets', [
            'id' => $mediaAsset->id,
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

    public function test_delete_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $mediaAssets = MediaAsset::factory()->count(3)->for($user)->create();
        $otherUser = User::factory()->create();
        $otherMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        $this->withMediaRateLimitOverride(
            MediaAssetRateLimiter::DELETE_NAME,
            [$user->id, $otherUser->id],
            function () use ($mediaAssets, $otherMediaAsset, $otherUser, $user): void {
                foreach ($mediaAssets->take(2) as $mediaAsset) {
                    $this
                        ->deleteJson("/api/media-assets/{$mediaAsset->id}")
                        ->assertNoContent();
                }

                $this->signIn($otherUser);

                $this
                    ->deleteJson("/api/media-assets/{$otherMediaAsset->id}")
                    ->assertNoContent();

                $this->signIn($user);

                $blockedMediaAsset = $mediaAssets->last();

                $this
                    ->deleteJson("/api/media-assets/{$blockedMediaAsset->id}")
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson('/api/media-assets')
                    ->assertOk()
                    ->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.id', $blockedMediaAsset->id);

                $this->assertDatabaseMissing('media_assets', ['id' => $mediaAssets[0]->id]);
                $this->assertDatabaseMissing('media_assets', ['id' => $mediaAssets[1]->id]);
                $this->assertDatabaseMissing('media_assets', ['id' => $otherMediaAsset->id]);
                $this->assertDatabaseHas('media_assets', [
                    'id' => $blockedMediaAsset->id,
                    'user_id' => $user->id,
                ]);
                $this->assertDatabaseMissing('sync_feed_entries', [
                    'resource_type' => MediaAssetSyncPayload::RESOURCE_TYPE,
                    'resource_id' => $blockedMediaAsset->id,
                ]);
            },
        );
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
