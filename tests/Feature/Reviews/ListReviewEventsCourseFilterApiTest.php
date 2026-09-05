<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;

class ListReviewEventsCourseFilterApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_filters_review_events_by_course_id(): void
    {
        [$course, $deck, $card, $otherCard] = $this->courseFilterCards();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();
        $otherCourseEvent = CardReviewEvent::factory()->for($otherCard)->create();
        $otherUserEvent = $this->cardReviewEventFor(User::factory()->create());

        $response = $this->getJson("/api/card-review-events?course_id={$course->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing([
                'id' => $otherCourseEvent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserEvent->id,
            ]);
    }

    /** @return array{Course, Deck, Card, Card} */
    private function courseFilterCards(): array
    {
        [$course, $deck, $otherDeck] = $this->courseFilterDecks();

        return [
            $course,
            $deck,
            Card::factory()->for($deck)->create(),
            Card::factory()->for($otherDeck)->create(),
        ];
    }

    /** @return array{Course, Deck, Deck} */
    private function courseFilterDecks(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();

        return [
            $course,
            Deck::factory()->for($course)->for($user)->create(),
            Deck::factory()->for($otherCourse)->for($user)->create(),
        ];
    }
}
