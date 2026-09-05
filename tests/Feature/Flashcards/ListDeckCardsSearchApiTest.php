<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDeckCardsSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_deck_cards_by_search_query(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $match = Card::factory()->for($deck)->create([
            'front_text' => 'Photosynthesis',
            'search_text' => 'Photosynthesis makes glucose',
        ]);
        $nonMatch = Card::factory()->for($deck)->create([
            'front_text' => 'Respiration',
            'search_text' => 'Cellular respiration releases energy',
        ]);
        $otherDeckCard = Card::factory()->for($otherDeck)->create([
            'search_text' => 'Photosynthesis from another deck',
        ]);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?q=photo");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonMissing(['id' => $nonMatch->id])
            ->assertJsonMissing(['id' => $otherDeckCard->id]);
    }

    public function test_it_trims_deck_card_search_queries_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $match = Card::factory()->for($deck)->create([
            'search_text' => 'Photosynthesis makes glucose',
        ]);
        $nonMatch = Card::factory()->for($deck)->create([
            'search_text' => 'Cellular respiration releases energy',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/decks/{$deck->id}/cards?q=%20PHOTO%20");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonMissing(['id' => $nonMatch->id]);
    }

    public function test_it_rejects_a_blank_deck_card_search_query_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/decks/{$deck->id}/cards?q=%20%20%20")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_it_rejects_an_array_deck_card_search_query(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?q[]=photo");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_it_rejects_an_overlong_deck_card_search_query(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?q=".str_repeat('a', 256));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }
}
