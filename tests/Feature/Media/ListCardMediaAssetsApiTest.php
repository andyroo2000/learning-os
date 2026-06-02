<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsMediaAssetManifests;
use Tests\TestCase;

class ListCardMediaAssetsApiTest extends TestCase
{
    use AssertsMediaAssetManifests, RefreshDatabase;

    public function test_it_lists_media_assets_attached_to_a_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.0.size_bytes', 123_456)
            ->assertJsonPath('data.0.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.0.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.url_expires_at');

        $this->assertMediaAssetResourceKeys($response->json('data.0'));
    }

    public function test_it_returns_an_empty_manifest_for_a_card_without_media(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_lists_only_media_attached_to_the_requested_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();
        $otherMediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);
        $otherCard->mediaAssets()->attach($otherMediaAsset->id);

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mediaAsset->id)
            ->assertJsonMissingPath('data.1');
    }

    public function test_it_returns_media_assets_in_id_order(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $earlierMediaAsset = MediaAsset::factory()->create();
        $laterMediaAsset = MediaAsset::factory()->create();

        $this->assertLessThan($laterMediaAsset->id, $earlierMediaAsset->id);

        $card->mediaAssets()->attach($laterMediaAsset->id);
        $card->mediaAssets()->attach($earlierMediaAsset->id);

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $earlierMediaAsset->id)
            ->assertJsonPath('data.1.id', $laterMediaAsset->id);
    }

    public function test_it_rejects_missing_card(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/'.((string) Str::ulid()).'/media-assets');

        $response->assertNotFound();
    }

    public function test_it_rejects_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/not-a-ulid/media-assets');

        $response->assertNotFound();
    }

    public function test_it_rejects_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->getJson("/api/cards/{$otherCard->id}/media-assets");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->getJson("/api/cards/{$card->id}/media-assets");

        $response->assertUnauthorized();
    }
}
