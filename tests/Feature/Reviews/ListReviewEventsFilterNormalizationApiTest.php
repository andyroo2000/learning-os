<?php

namespace Tests\Feature\Reviews;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

class ListReviewEventsFilterNormalizationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_rejects_a_blank_course_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?course_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');
    }

    public function test_it_rejects_a_blank_card_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?card_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_id');
    }

    public function test_it_rejects_a_blank_deck_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/card-review-events?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deck_id');
    }
}
