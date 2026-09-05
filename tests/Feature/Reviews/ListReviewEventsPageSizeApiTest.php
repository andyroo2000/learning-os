<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Pagination\CursorPagination;

class ListReviewEventsPageSizeApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(3)->for($card)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/card-review-events');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($card)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/card-review-events');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(3)->for($card)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/card-review-events');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/card-review-events');
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/card-review-events', CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/card-review-events', 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/card-review-events', -1);
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/card-review-events', 'abc');
    }

    public function test_it_rejects_an_array_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsArrayPageSize('/api/card-review-events');
    }

    public function test_it_rejects_a_blank_page_size_without_global_trim_middleware(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsBlankPageSizeWithoutTrimMiddleware('/api/card-review-events');
    }

    public function test_it_rejects_invalid_cursor_values(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsMalformedCursor('/api/card-review-events');
        $this->assertCursorEndpointRejectsArrayCursor('/api/card-review-events');
        $this->assertCursorEndpointRejectsParameterlessCursor('/api/card-review-events');
    }
}
