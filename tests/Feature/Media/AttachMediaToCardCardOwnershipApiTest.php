<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachMediaToCardCardOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_missing_card(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson('/api/cards/'.((string) Str::ulid()).'/media-assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_malformed_card_id(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson('/api/cards/not-a-ulid/media-assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_another_users_card(): void
    {
        $user = $this->signIn();
        $otherCard = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson("/api/cards/{$otherCard->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_hides_another_users_card_before_media_asset_validation(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson("/api/cards/{$otherCard->id}/media-assets", [
            'media_asset_id' => 'not-a-ulid',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }
}
