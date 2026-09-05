<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardsBoundaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_excludes_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $visibleCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();

        $deletedCard->delete();

        $this->assertOnlyCardIsListed($visibleCard, $deletedCard);
    }

    public function test_it_excludes_cards_from_soft_deleted_decks(): void
    {
        $user = $this->signIn();
        $visibleCard = $this->cardFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();

        $deletedDeck->delete();

        $this->assertOnlyCardIsListed($visibleCard, $deletedDeckCard);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/cards');

        $response->assertUnauthorized();
    }

    private function assertOnlyCardIsListed(Card $visibleCard, Card $excludedCard): void
    {
        $this->getJson('/api/cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCard->id)
            ->assertJsonMissing([
                'id' => $excludedCard->id,
            ]);
    }
}
