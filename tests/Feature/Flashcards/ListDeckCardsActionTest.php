<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ListDeckCardsAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDeckCardsActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_caps_the_page_size(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListDeckCardsAction::class)->handle(
            $deck,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListDeckCardsAction::class)->handle($deck);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        Card::factory()->count(2)->for($deck)->create();

        $cards = app(ListDeckCardsAction::class)->handle(
            $deck,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $cards->perPage());
        $this->assertCount(1, $cards->items());
    }

    public function test_it_filters_deck_cards_by_study_status_for_direct_callers(): void
    {
        $deck = $this->deckFor(User::factory()->create());
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New);

        $cards = app(ListDeckCardsAction::class)->handle(
            deck: $deck,
            studyStatus: ' REVIEW ',
        );

        $this->assertSame([$reviewCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_accepts_study_status_enums_for_direct_callers(): void
    {
        $deck = $this->deckFor(User::factory()->create());
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New);

        $cards = app(ListDeckCardsAction::class)->handle(
            deck: $deck,
            studyStatus: CardStudyStatus::Review,
        );

        $this->assertSame([$reviewCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_study_status_filters_for_direct_callers(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status filter must not be blank when provided.');

        app(ListDeckCardsAction::class)->handle(
            deck: $deck,
            studyStatus: '   ',
        );
    }
}
