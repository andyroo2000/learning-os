<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardsCardTypeFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_cards_by_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $productionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Production,
        ]);
        $recognitionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);
        $otherUserCard = Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'card_type' => CardType::Production,
        ]);

        $response = $this->getJson('/api/cards?card_type=production');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productionCard->id)
            ->assertJsonPath('data.0.card_type', 'production')
            ->assertJsonMissing(['id' => $recognitionCard->id])
            ->assertJsonMissing(['id' => $otherUserCard->id]);
    }

    public function test_it_normalizes_card_type_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $productionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Production,
        ]);
        $recognitionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?card_type=%20PRODUCTION%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productionCard->id)
            ->assertJsonMissing(['id' => $recognitionCard->id]);
    }

    public function test_it_rejects_a_blank_card_type_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?card_type=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);
    }

    public function test_it_rejects_a_malformed_card_type_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?card_type=reverse');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);
    }

    public function test_it_rejects_an_array_card_type_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?card_type[]=production');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);
    }
}
