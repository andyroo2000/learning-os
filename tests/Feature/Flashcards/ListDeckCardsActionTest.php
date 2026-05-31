<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ListDeckCardsAction;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDeckCardsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        Card::factory()->count(ListDeckCardsAction::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListDeckCardsAction::class)->handle($deck, ListDeckCardsAction::MAX_PAGE_SIZE + 1);

        $this->assertSame(ListDeckCardsAction::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(ListDeckCardsAction::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        Card::factory()->count(2)->for($deck)->create();

        $cards = app(ListDeckCardsAction::class)->handle($deck, 0);

        $this->assertSame(1, $cards->perPage());
        $this->assertCount(1, $cards->items());
    }
}
