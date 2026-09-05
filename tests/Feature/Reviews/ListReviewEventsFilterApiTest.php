<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;

class ListReviewEventsFilterApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_requires_card_id_filters_to_match_the_course_filter_when_both_are_provided(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $otherCourseEvent = CardReviewEvent::factory()->for($otherCourseCard)->create();

        $response = $this->getJson("/api/card-review-events?course_id={$course->id}&card_id={$otherCourseCard->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherCourseEvent->id,
            ]);
    }

    public function test_it_returns_empty_when_deck_id_and_course_id_are_in_different_courses(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $otherCourseEvent = CardReviewEvent::factory()->for($otherCourseCard)->create();

        $response = $this->getJson("/api/card-review-events?course_id={$course->id}&deck_id={$otherCourseDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherCourseEvent->id,
            ]);
    }

    public function test_it_returns_empty_when_card_id_and_deck_id_do_not_match(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherDeckEvent = CardReviewEvent::factory()->for($otherDeckCard)->create();

        $response = $this->getJson("/api/card-review-events?deck_id={$deck->id}&card_id={$otherDeckCard->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherDeckEvent->id,
            ]);
    }
}
