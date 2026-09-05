<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsDeckFilterNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_deck_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $deckMediaAsset = MediaAsset::factory()->for($user)->create();
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($deckMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?deck_id=%20'.strtoupper($deck->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckMediaAsset->id)
            ->assertJsonMissing([
                'id' => $otherDeckMediaAsset->id,
            ]);
    }
}
