<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\ListDeckMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDeckMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_unique_user_owned_media_attached_to_cards_in_a_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();
        $otherDeck = $this->deckFor($user);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $firstCard->mediaAssets()->attach($mediaAsset->id);
        $secondCard->mediaAssets()->attach($mediaAsset->id);
        $firstCard->mediaAssets()->attach($crossUserMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);

        $mediaAssets = app(ListDeckMediaAssetsAction::class)->handle($deck);

        $this->assertSame([$mediaAsset->id], $mediaAssets->pluck('id')->all());
    }
}
