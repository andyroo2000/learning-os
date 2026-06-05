<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ListNewCardsAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListNewCardsActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_queued_new_cards_for_a_user_in_queue_order(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01j00000000000000000000002',
            'new_queue_position' => 2,
        ]);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $tieBreakCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'id' => '01j00000000000000000000003',
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Suspended, [
            'new_queue_position' => 4,
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $deletedDeck = $this->deckFor($user);
        $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New, [
            'new_queue_position' => 5,
        ]);
        $deletedDeck->delete();

        $cards = app(ListNewCardsAction::class)->handle(userId: $user->id);

        $this->assertSame(
            [$firstCard->id, $secondCard->id, $tieBreakCard->id],
            collect($cards->items())->pluck('id')->all(),
        );
    }

    public function test_it_filters_new_cards_by_course_id_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $courseCard = $this->cardWithStudyStatus($courseDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $cards = app(ListNewCardsAction::class)->handle(
            userId: $user->id,
            courseId: ' '.strtoupper($course->id).' ',
        );

        $this->assertSame([$courseCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_filters_new_cards_by_deck_id_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $cards = app(ListNewCardsAction::class)->handle(
            userId: $user->id,
            deckId: ' '.strtoupper($deck->id).' ',
        );

        $this->assertSame([$deckCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_returns_empty_when_deck_id_and_course_id_are_in_different_courses(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);

        $this->cardWithStudyStatus($otherCourseDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $cards = app(ListNewCardsAction::class)->handle(
            userId: $user->id,
            courseId: $course->id,
            deckId: $otherCourseDeck->id,
        );

        $this->assertEmpty($cards->items());
    }

    public function test_it_returns_empty_results_for_a_deck_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherDeck = $this->deckFor(User::factory()->create());

        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $cards = app(ListNewCardsAction::class)->handle(
            userId: $user->id,
            deckId: $otherDeck->id,
        );

        $this->assertEmpty($cards->items());
    }

    public function test_it_rejects_blank_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('New card course_id filter must not be blank when provided.');

        app(ListNewCardsAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_blank_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('New card deck_id filter must not be blank when provided.');

        app(ListNewCardsAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }

    public function test_it_uses_the_requested_page_size(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);

        Card::factory()
            ->count(3)
            ->sequence(
                ['new_queue_position' => 1],
                ['new_queue_position' => 2],
                ['new_queue_position' => 3],
            )
            ->for($deck)
            ->create([
                'study_status' => CardStudyStatus::New,
            ]);

        $cards = app(ListNewCardsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame(2, $cards->perPage());
        $this->assertCount(2, $cards->items());
    }
}
