<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Media\Concerns\UsesMediaRateLimitOverrides;
use Tests\TestCase;

class AttachMediaToCardRateLimitApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesMediaRateLimitOverrides;

    public function test_attach_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAssets = MediaAsset::factory()->count(3)->for($user)->create();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);
        $otherMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        $this->withMediaRateLimitOverride(
            CardMediaRateLimiter::ATTACH_NAME,
            [$user->id, $otherUser->id],
            function () use ($card, $mediaAssets, $otherCard, $otherMediaAsset, $otherUser, $user): void {
                foreach ($mediaAssets->take(2) as $mediaAsset) {
                    $this
                        ->postJson("/api/cards/{$card->id}/media-assets", ['media_asset_id' => $mediaAsset->id])
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson("/api/cards/{$otherCard->id}/media-assets", ['media_asset_id' => $otherMediaAsset->id])
                    ->assertOk();

                $this->signIn($user);

                $blockedMediaAsset = $mediaAssets->last();

                $this
                    ->postJson("/api/cards/{$card->id}/media-assets", ['media_asset_id' => $blockedMediaAsset->id])
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$card->id}/media-assets")
                    ->assertOk()
                    ->assertJsonCount(2, 'data');

                $this->assertDatabaseHas('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $mediaAssets[0]->id,
                ]);
                $this->assertDatabaseHas('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $mediaAssets[1]->id,
                ]);
                $this->assertDatabaseHas('card_media', [
                    'card_id' => $otherCard->id,
                    'media_asset_id' => $otherMediaAsset->id,
                ]);
                $this->assertDatabaseMissing('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $blockedMediaAsset->id,
                ]);
                $this->assertDatabaseMissing('sync_feed_entries', [
                    'resource_id' => CardMediaSyncPayload::resourceId($card->id, $blockedMediaAsset->id),
                ]);
            },
        );
    }
}
