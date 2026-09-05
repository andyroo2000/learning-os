<?php

namespace Tests\Feature\Reviews;

class ListReviewEventsFilterValidationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_rejects_a_malformed_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');
    }

    public function test_it_rejects_a_malformed_card_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?card_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_id');
    }

    public function test_it_rejects_a_malformed_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deck_id');
    }

    public function test_it_rejects_an_array_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?course_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_id');
    }

    public function test_it_rejects_an_array_card_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?card_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_id');
    }

    public function test_it_rejects_an_array_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?deck_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deck_id');
    }
}
