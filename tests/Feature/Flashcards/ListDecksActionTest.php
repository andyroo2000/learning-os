<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDecksActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();

        Deck::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $decks = app(ListDecksAction::class)->handle($user->id, CursorPagination::MAX_PAGE_SIZE + 1);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $decks->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $decks->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();

        Deck::factory()->count(2)->for($user)->create();

        $decks = app(ListDecksAction::class)->handle($user->id, 0);

        $this->assertSame(1, $decks->perPage());
        $this->assertCount(1, $decks->items());
    }
}
