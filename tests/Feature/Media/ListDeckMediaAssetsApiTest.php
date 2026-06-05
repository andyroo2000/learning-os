<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsMediaAssetManifests;
use Tests\TestCase;

class ListDeckMediaAssetsApiTest extends TestCase
{
    use AssertsMediaAssetManifests, RefreshDatabase;

    public function test_it_lists_unique_media_assets_attached_to_cards_in_a_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $firstCard->mediaAssets()->attach($mediaAsset->id);
        $secondCard->mediaAssets()->attach($mediaAsset->id);
        $this->cardFor($user)->mediaAssets()->attach($otherDeckMediaAsset->id);

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.0.content_url', "/api/media-assets/{$mediaAsset->id}/content")
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.0.size_bytes', 123_456)
            ->assertJsonPath('data.0.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.0.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.url_expires_at')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        $this->assertMediaAssetResourceKeys($response->json('data.0'));
    }

    public function test_it_returns_an_empty_manifest_for_a_deck_without_media(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response
            ->assertOk()
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_excludes_cross_user_media_attached_to_a_card_in_the_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();

        $card->mediaAssets()->attach($crossUserMediaAsset->id);

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response
            ->assertOk()
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_lists_media_assets_in_id_order(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $earlierMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
        ]);
        $laterMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pb',
        ]);

        // Attach in reverse ID order to prove the manifest order is deterministic.
        $card->mediaAssets()->attach($laterMediaAsset->id);
        $card->mediaAssets()->attach($earlierMediaAsset->id);

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $earlierMediaAsset->id)
            ->assertJsonPath('data.1.id', $laterMediaAsset->id);
    }

    public function test_it_hides_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());

        $response = $this->getJson("/api/decks/{$otherDeck->id}/media-assets");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_deck(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks/'.((string) Str::ulid()).'/media-assets');

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_deck_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks/not-a-ulid/media-assets');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        $response = $this->getJson("/api/decks/{$deck->id}/media-assets");

        $response->assertUnauthorized();
    }
}
