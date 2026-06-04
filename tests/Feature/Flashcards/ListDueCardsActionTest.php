<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ListDueCardsAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDueCardsActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_due_active_cards_for_a_user_in_due_order(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstDueCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $secondDueCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Learning, [
            'due_at' => $now->copy()->subMinute(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->addMinute(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'due_at' => $now->copy()->subDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended, [
            'due_at' => $now->copy()->subDay(),
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review, [
            'due_at' => $now->copy()->subDay(),
        ]);

        $cards = app(ListDueCardsAction::class)->handle(
            userId: $user->id,
            now: $now,
        );

        $this->assertSame([$firstDueCard->id, $secondDueCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_filters_due_cards_by_course_id_for_direct_callers(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $courseCard = $this->cardWithStudyStatus($courseDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subHour(),
        ]);

        $cards = app(ListDueCardsAction::class)->handle(
            userId: $user->id,
            courseId: ' '.strtoupper($course->id).' ',
            now: $now,
        );

        $this->assertSame([$courseCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Due card course_id filter must not be blank when provided.');

        app(ListDueCardsAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_uses_the_requested_page_size(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        Card::factory()
            ->count(3)
            ->for($deck)
            ->create([
                'study_status' => CardStudyStatus::Review,
                'due_at' => $now->copy()->subHour(),
            ]);

        $cards = app(ListDueCardsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
            now: $now,
        );

        $this->assertSame(2, $cards->perPage());
        $this->assertCount(2, $cards->items());
    }
}
