<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

class ListReviewEventsEntityFilterNormalizationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_trims_card_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherCardEvent = CardReviewEvent::factory()->for($otherCard)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?card_id=%20'.$card->id.'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonMissing([
                'id' => $otherCardEvent->id,
            ]);
    }

    public function test_it_lowercases_card_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherCardEvent = CardReviewEvent::factory()->for($otherCard)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?card_id='.strtoupper($card->id));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonMissing([
                'id' => $otherCardEvent->id,
            ]);
    }

    public function test_it_normalizes_deck_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherCard = Card::factory()->for($otherDeck)->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherDeckEvent = CardReviewEvent::factory()->for($otherCard)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?deck_id=%20'.strtoupper($deck->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonMissing([
                'id' => $otherDeckEvent->id,
            ]);
    }
}
