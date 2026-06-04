<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListCardsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListCardsAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $cards = app(ListCardsAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $cards->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $cards->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()->count(2)->for($deck)->create();

        $cards = app(ListCardsAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $cards->perPage());
        $this->assertCount(1, $cards->items());
    }

    public function test_it_filters_cards_by_deck_course_id(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $otherCourse = Course::factory()->create(['user_id' => $user->id]);
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $standaloneDeck = $this->deckFor($user);
        $courseCard = Card::factory()->for($courseDeck)->create();
        Card::factory()->for($otherCourseDeck)->create();
        Card::factory()->for($standaloneDeck)->create();

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            courseId: ' '.$course->id.' ',
        );

        $this->assertSame([$courseCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_course_id_filters(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card course_id filter must not be blank when provided.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            courseId: '   ',
        );
    }
}
