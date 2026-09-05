<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Testing\TestResponse;

class ListReviewEventsCourseFilterNormalizationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_trims_course_id_filters_without_global_trim_middleware(): void
    {
        [$course, $courseCard, $standaloneCard] = $this->courseFilterCards();
        $reviewEvent = CardReviewEvent::factory()->for($courseCard)->create();
        $standaloneReviewEvent = CardReviewEvent::factory()->for($standaloneCard)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?course_id=%20'.$course->id.'%20');

        $this->assertNormalizedCourseFilterResponse($response, $course, $reviewEvent, $standaloneReviewEvent);
    }

    public function test_it_lowercases_course_id_filters_without_global_trim_middleware(): void
    {
        [$course, $courseCard, $standaloneCard] = $this->courseFilterCards();
        $reviewEvent = CardReviewEvent::factory()->for($courseCard)->create();
        $standaloneReviewEvent = CardReviewEvent::factory()->for($standaloneCard)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?course_id='.strtoupper($course->id));

        $this->assertNormalizedCourseFilterResponse($response, $course, $reviewEvent, $standaloneReviewEvent);
    }

    private function assertNormalizedCourseFilterResponse(
        TestResponse $response,
        Course $course,
        CardReviewEvent $reviewEvent,
        CardReviewEvent $standaloneReviewEvent,
    ): void {
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewEvent->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonMissing([
                'id' => $standaloneReviewEvent->id,
            ]);
    }

    /** @return array{Course, Card, Card} */
    private function courseFilterCards(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $standaloneDeck = Deck::factory()->for($user)->create();

        return [
            $course,
            Card::factory()->for($courseDeck)->create(),
            Card::factory()->for($standaloneDeck)->create(),
        ];
    }
}
