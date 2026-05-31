<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ListCardReviewEventsAction;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
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

        $reviewEvents = app(ListCardReviewEventsAction::class)->handle($card, CursorPagination::MAX_PAGE_SIZE + 1);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $card = $this->cardFor(User::factory()->create());

        CardReviewEvent::factory()->count(2)->for($card)->create();

        $reviewEvents = app(ListCardReviewEventsAction::class)->handle($card, 0);

        $this->assertSame(1, $reviewEvents->perPage());
        $this->assertCount(1, $reviewEvents->items());
    }
}
