<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListDecksActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();

        Deck::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $decks = app(ListDecksAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $decks->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $decks->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();

        Deck::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $decks = app(ListDecksAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $decks->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $decks->items());
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();

        Deck::factory()->count(2)->for($user)->create();

        $decks = app(ListDecksAction::class)->handle(
            $user->id,
            CursorPageSize::fromPerPage(0),
        );

        $this->assertSame(1, $decks->perPage());
        $this->assertCount(1, $decks->items());
    }

    public function test_it_filters_decks_by_course_id(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $otherCourse = Course::factory()->create(['user_id' => $user->id]);
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        Deck::factory()->for($otherCourse)->for($user)->create();
        Deck::factory()->for($user)->create();

        $decks = app(ListDecksAction::class)->handle(
            userId: $user->id,
            courseId: ' '.$course->id.' ',
        );

        $this->assertSame([$courseDeck->id], collect($decks->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_course_id_filters(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck course_id filter must not be blank when provided.');

        app(ListDecksAction::class)->handle(
            userId: $user->id,
            courseId: '   ',
        );
    }
}
