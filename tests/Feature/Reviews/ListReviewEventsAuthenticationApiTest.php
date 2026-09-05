<?php

namespace Tests\Feature\Reviews;

class ListReviewEventsAuthenticationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/card-review-events');

        $response->assertUnauthorized();
    }
}
