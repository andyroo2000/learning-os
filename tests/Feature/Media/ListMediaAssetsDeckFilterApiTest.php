<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsDeckFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_media_assets_by_deck_id(): void
    {
        $user = $this->signIn();
        [$deck, $card] = $this->deckCardFor($user);
        $otherDeck = Deck::factory()->for($user)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $deckMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/deck.jpg')
            ->create([
                'created_at' => now(),
            ]);
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();

        $card->mediaAssets()->attach($deckMediaAsset->id);
        $secondCard->mediaAssets()->attach($deckMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);
        $card->mediaAssets()->attach($crossUserMediaAsset->id);

        $response = $this->getJson("/api/media-assets?deck_id={$deck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckMediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/deck.jpg')
            ->assertJsonMissing([
                'id' => $otherDeckMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $unattachedMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $crossUserMediaAsset->id,
            ]);
    }

    public function test_it_returns_empty_results_for_a_deck_id_owned_by_another_user(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherUserMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        $otherDeckCard->mediaAssets()->attach($otherUserMediaAsset->id);

        $response = $this->getJson("/api/media-assets?deck_id={$otherDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherUserMediaAsset->id,
            ]);
    }

    public function test_it_excludes_deleted_card_and_deck_attachments_when_filtering_by_deck_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $visibleCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        $visibleMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now(),
        ]);
        $deletedCardMediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $visibleCard->mediaAssets()->attach($visibleMediaAsset->id);
        $deletedCard->mediaAssets()->attach($deletedCardMediaAsset->id);
        $deletedDeckCard->mediaAssets()->attach($deletedDeckMediaAsset->id);
        $deletedCard->delete();
        $deletedDeck->delete();

        $activeDeckResponse = $this->getJson("/api/media-assets?deck_id={$deck->id}");

        $activeDeckResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleMediaAsset->id)
            ->assertJsonMissing([
                'id' => $deletedCardMediaAsset->id,
            ]);

        $deletedDeckResponse = $this->getJson("/api/media-assets?deck_id={$deletedDeck->id}");

        $deletedDeckResponse
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $deletedDeckMediaAsset->id,
            ]);
    }

    /**
     * @return array{Deck, Card}
     */
    private function deckCardFor(User $user): array
    {
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();

        return [$deck, Card::factory()->for($deck)->create()];
    }
}
