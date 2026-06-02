<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ListCardReviewEventsAction;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardReviewEventsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $card = $this->cardFor(User::factory()->create());

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $reviewEvents = app(ListCardReviewEventsAction::class)->handle(
            $card,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $card = $this->cardFor(User::factory()->create());

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $reviewEvents = app(ListCardReviewEventsAction::class)->handle($card);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $card = $this->cardFor(User::factory()->create());

        CardReviewEvent::factory()->count(2)->for($card)->create();

        $reviewEvents = app(ListCardReviewEventsAction::class)->handle(
            $card,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $reviewEvents->perPage());
        $this->assertCount(1, $reviewEvents->items());
    }
}
