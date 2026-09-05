<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;

class ListReviewEventsDeckFilterApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_filters_review_events_by_deck_id(): void
    {
        [$course, $deck, $card, $otherCard] = $this->deckFilterCards();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherDeckEvent = CardReviewEvent::factory()->for($otherCard)->create();
        $otherUserEvent = $this->cardReviewEventFor(User::factory()->create());

        $response = $this->getJson("/api/card-review-events?deck_id={$deck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing([
                'id' => $otherDeckEvent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserEvent->id,
            ]);
    }

    /** @return array{Course, Deck, Card, Card} */
    private function deckFilterCards(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $otherDeck = Deck::factory()->for($user)->create();

        return [
            $course,
            $deck,
            Card::factory()->for($deck)->create(),
            Card::factory()->for($otherDeck)->create(),
        ];
    }
}
