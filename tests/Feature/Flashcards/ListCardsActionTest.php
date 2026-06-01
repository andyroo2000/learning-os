<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCardsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListCardsAction::class)->handle($user->id, CursorPagination::MAX_PAGE_SIZE + 1);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()->count(2)->for($deck)->create();

        $cards = app(ListCardsAction::class)->handle($user->id, 0);

        $this->assertSame(1, $cards->perPage());
        $this->assertCount(1, $cards->items());
    }
}
