<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DetachMediaFromCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detaches_media_from_a_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $detachedMediaAsset = MediaAsset::factory()->for($user)->create();
        $remainingMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/remaining.jpg')
            ->create();

        $card->mediaAssets()->attach([$detachedMediaAsset->id, $remainingMediaAsset->id]);

        $response = $this->deleteJson("/api/cards/{$card->id}/media-assets/{$detachedMediaAsset->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonCount(1, 'data.media_assets')
            ->assertJsonPath('data.media_assets.0.id', $remainingMediaAsset->id)
            ->assertJsonPath('data.media_assets.0.url', 'https://cdn.example.test/uploads/remaining.jpg');

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $detachedMediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $remainingMediaAsset->id,
        ]);
    }

    public function test_it_is_idempotent_when_attachment_is_already_missing(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->deleteJson("/api/cards/{$card->id}/media-assets/{$mediaAsset->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonCount(0, 'data.media_assets');

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_missing_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->deleteJson('/api/cards/'.$card->id.'/media-assets/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_rejects_malformed_media_asset_id(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->deleteJson("/api/cards/{$card->id}/media-assets/not-a-ulid");

        $response->assertNotFound();
    }

    public function test_it_rejects_another_users_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->create();

        // This cannot happen through the attach API, but protects against stale or imported rows.
        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->deleteJson("/api/cards/{$card->id}/media-assets/{$mediaAsset->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_it_rejects_missing_card(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->deleteJson('/api/cards/'.((string) Str::ulid())."/media-assets/{$mediaAsset->id}");

        $response->assertNotFound();
    }

    public function test_it_rejects_malformed_card_id(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->deleteJson("/api/cards/not-a-ulid/media-assets/{$mediaAsset->id}");

        $response->assertNotFound();
    }

    public function test_it_rejects_another_users_card(): void
    {
        $user = $this->signIn();
        $otherCard = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->deleteJson("/api/cards/{$otherCard->id}/media-assets/{$mediaAsset->id}");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->deleteJson("/api/cards/{$card->id}/media-assets/{$mediaAsset->id}");

        $response->assertUnauthorized();
    }
}
