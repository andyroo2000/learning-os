<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ListReviewEventsAction;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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

    public function test_it_filters_results_by_course_id(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $otherDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        $otherCard = Card::factory()->for($otherDeck)->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        CardReviewEvent::factory()->for($otherCard)->create();
        $this->cardReviewEventFor(User::factory()->create());

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, courseId: ' '.strtoupper($course->id).' ');
        $reviewEventIds = collect($reviewEvents->items())->pluck('id')->all();

        $this->assertSame([$reviewEvent->id], $reviewEventIds);
    }

    public function test_it_filters_results_by_card_id(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        CardReviewEvent::factory()->for($otherCard)->create();
        $this->cardReviewEventFor(User::factory()->create());

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, cardId: ' '.strtoupper($card->id).' ');
        $reviewEventIds = collect($reviewEvents->items())->pluck('id')->all();

        $this->assertSame([$reviewEvent->id], $reviewEventIds);
    }

    public function test_it_filters_results_by_deck_id(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherCard = Card::factory()->for($otherDeck)->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        CardReviewEvent::factory()->for($otherCard)->create();
        $this->cardReviewEventFor(User::factory()->create());

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, deckId: ' '.strtoupper($deck->id).' ');
        $reviewEventIds = collect($reviewEvents->items())->pluck('id')->all();

        $this->assertSame([$reviewEvent->id], $reviewEventIds);
    }

    public function test_it_requires_card_id_filters_to_match_the_course_filter_when_both_are_provided(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();

        CardReviewEvent::factory()->for($otherCourseCard)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle(
            $user->id,
            courseId: $course->id,
            cardId: $otherCourseCard->id,
        );

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_returns_empty_when_deck_id_and_course_id_are_in_different_courses(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();

        CardReviewEvent::factory()->for($otherCourseCard)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle(
            $user->id,
            courseId: $course->id,
            deckId: $otherCourseDeck->id,
        );

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_returns_empty_when_card_id_and_deck_id_do_not_match(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();

        CardReviewEvent::factory()->for($otherDeckCard)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle(
            $user->id,
            deckId: $deck->id,
            cardId: $otherDeckCard->id,
        );

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_returns_empty_results_for_a_course_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCourse = Course::factory()->for($otherUser)->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, courseId: $otherCourse->id);

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_returns_empty_results_for_a_card_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, cardId: $otherCard->id);

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_returns_empty_results_for_a_deck_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherDeck = $this->deckFor(User::factory()->create());

        CardReviewEvent::factory()->for(Card::factory()->for($otherDeck)->create())->create();

        $reviewEvents = app(ListReviewEventsAction::class)->handle($user->id, deckId: $otherDeck->id);

        $this->assertEmpty($reviewEvents->items());
    }

    public function test_it_rejects_blank_course_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event course_id filter must not be blank when provided.');

        app(ListReviewEventsAction::class)->handle(User::factory()->create()->id, courseId: '   ');
    }

    public function test_it_rejects_blank_deck_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event deck_id filter must not be blank when provided.');

        app(ListReviewEventsAction::class)->handle(User::factory()->create()->id, deckId: '   ');
    }

    public function test_it_rejects_blank_card_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event card_id filter must not be blank when provided.');

        app(ListReviewEventsAction::class)->handle(User::factory()->create()->id, cardId: '   ');
    }
}
