<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ListReviewEventsAction;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListReviewEventsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $reviewEvents->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(2)->for($card)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $reviewEvents->perPage());
        $this->assertCount(1, $reviewEvents->items());
    }

    public function test_it_scopes_results_to_active_cards_in_the_users_active_decks(): void
    {
        $user = User::factory()->create();
        $visibleEvent = $this->cardReviewEventFor($user);
        $this->cardReviewEventFor(User::factory()->create());
        $deletedCard = $this->cardFor($user);
        CardReviewEvent::factory()->for($deletedCard)->create();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        CardReviewEvent::factory()->for($deletedDeckCard)->create();

        $deletedCard->delete();
        $deletedDeck->delete();

        // Reset the deck-delete cascade to test deck-level exclusion independently.
        DB::table('cards')
            ->where('id', $deletedDeckCard->id)
            ->update(['deleted_at' => null]);

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id);
        $reviewEventIds = collect($reviewEvents->items())->pluck('id')->all();

        $this->assertSame([$visibleEvent->id], $reviewEventIds);
    }
}
