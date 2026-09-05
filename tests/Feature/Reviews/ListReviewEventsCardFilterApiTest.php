<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;

class ListReviewEventsCardFilterApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_filters_review_events_by_card_id(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherCardEvent = CardReviewEvent::factory()->for($otherCard)->create();
        $otherUserEvent = $this->cardReviewEventFor(User::factory()->create());

        $response = $this->getJson("/api/card-review-events?card_id={$card->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonMissing([
                'id' => $otherCardEvent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserEvent->id,
            ]);
    }
}
