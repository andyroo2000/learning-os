<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ShowStudyCardCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_returns_the_unwrapped_compatibility_card_for_canonical_or_client_ids(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $card = $this->cardWithStudyStatus($this->deckFor($user), CardStudyStatus::Review);

        foreach ([$card->id, $card->clientId()] as $identifier) {
            $this->getJson("/api/study/cards/{$identifier}")
                ->assertOk()
                ->assertJsonPath('id', $card->clientId())
                ->assertJsonPath('syncId', $card->id)
                ->assertJsonStructure(['prompt', 'answer', 'state']);
        }
    }

    public function test_it_hides_cross_user_and_deleted_deck_cards(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $otherCard = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::New,
        );
        $deletedDeck = $this->deckFor($user);
        $deletedCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New);
        $deletedDeck->delete();

        $this->getJson("/api/study/cards/{$otherCard->id}")->assertNotFound();
        $this->getJson("/api/study/cards/{$deletedCard->id}")->assertNotFound();
    }
}
