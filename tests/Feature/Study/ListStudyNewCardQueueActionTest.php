<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListStudyNewCardQueueActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_new_cards_without_a_search_query_from_the_zero_offset(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            cursor: 0,
            limit: 100,
        );

        $this->assertSame(2, $page['total']);
        $this->assertSame(100, $page['limit']);
        $this->assertNull($page['nextCursor']);
        $this->assertSame([$firstCard->id, $secondCard->id], $page['items']->pluck('id')->all());
    }

    public function test_it_uses_default_cursor_and_limit_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $card = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(userId: $user->id);

        $this->assertSame(NewCardQueueLimits::PAGE_SIZE_DEFAULT, $page['limit']);
        $this->assertNull($page['nextCursor']);
        $this->assertSame([$card->id], $page['items']->pluck('id')->all());
    }

    public function test_it_accepts_boundary_limits_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $card = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $minimum = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            limit: 1,
        );
        $maximum = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            limit: NewCardQueueLimits::PAGE_SIZE_MAX,
        );

        $this->assertSame(1, $minimum['limit']);
        $this->assertSame(NewCardQueueLimits::PAGE_SIZE_MAX, $maximum['limit']);
        $this->assertSame([$card->id], $minimum['items']->pluck('id')->all());
        $this->assertSame([$card->id], $maximum['items']->pluck('id')->all());
    }

    public function test_it_lists_searchable_new_cards_with_offset_cursor_pagination(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '会社',
            'back_text' => 'company',
            'search_text' => '会社 company',
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '株式会社',
            'back_text' => 'corporation',
            'search_text' => '株式会社 corporation',
            'new_queue_position' => 2,
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '会社員',
            'back_text' => 'employee',
            'search_text' => '会社員 employee',
            'new_queue_position' => null,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '学校',
            'back_text' => 'school',
            'search_text' => '学校 school',
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'front_text' => '会社',
            'back_text' => 'company',
            'search_text' => '会社 company other user',
            'new_queue_position' => 1,
        ]);

        $firstPage = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            limit: 2,
            q: '会社',
        );

        $this->assertSame(3, $firstPage['total']);
        $this->assertSame(2, $firstPage['limit']);
        $this->assertSame('2', $firstPage['nextCursor']);
        $this->assertSame([$firstCard->id, $secondCard->id], $firstPage['items']->pluck('id')->all());

        $secondPage = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            cursor: 2,
            limit: 2,
            q: '会社',
        );

        $this->assertSame(3, $secondPage['total']);
        $this->assertNull($secondPage['nextCursor']);
        $this->assertSame([$legacyNullPositionCard->id], $secondPage['items']->pluck('id')->all());
    }

    public function test_it_filters_new_cards_by_course_id_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        $courseCard = $this->cardWithStudyStatus($courseDeck, CardStudyStatus::New, [
            'search_text' => '会社 company',
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'search_text' => '会社 outside course',
            'new_queue_position' => 2,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            q: '会社',
            courseId: ' '.strtoupper($course->id).' ',
        );

        $this->assertSame(1, $page['total']);
        $this->assertSame([$courseCard->id], $page['items']->pluck('id')->all());
    }

    public function test_it_filters_new_cards_by_deck_id_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $deckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            deckId: ' '.strtoupper($deck->id).' ',
        );

        $this->assertSame(1, $page['total']);
        $this->assertSame([$deckCard->id], $page['items']->pluck('id')->all());
    }

    public function test_it_returns_empty_when_course_and_deck_filters_do_not_match(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        $this->cardWithStudyStatus($otherCourseDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            courseId: $course->id,
            deckId: $otherCourseDeck->id,
        );

        $this->assertSame(0, $page['total']);
        $this->assertNull($page['nextCursor']);
        $this->assertEmpty($page['items']);
    }

    public function test_it_hides_deck_filters_owned_by_other_users(): void
    {
        $user = User::factory()->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            deckId: $otherDeck->id,
        );

        $this->assertSame(0, $page['total']);
        $this->assertEmpty($page['items']);
    }

    public function test_it_preserves_created_at_tiebreaking_with_null_queue_positions_last(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $newerCardWithSamePosition = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'created_at' => Carbon::parse('2026-06-05T12:00:00Z'),
        ]);
        $olderCardWithSamePosition = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'created_at' => Carbon::parse('2026-06-04T12:00:00Z'),
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => null,
            'created_at' => Carbon::parse('2026-06-03T12:00:00Z'),
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(userId: $user->id);

        $this->assertSame(
            [$olderCardWithSamePosition->id, $newerCardWithSamePosition->id, $legacyNullPositionCard->id],
            $page['items']->pluck('id')->all(),
        );
    }

    public function test_it_rejects_negative_cursors_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cursor must be a non-negative integer.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            cursor: -1,
        );
    }

    #[DataProvider('invalidLimitProvider')]
    public function test_it_rejects_invalid_limits_for_direct_callers(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.'.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            limit: $limit,
        );
    }

    public function test_it_rejects_blank_search_queries_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card search query filter must not be blank when provided.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            q: '   ',
        );
    }

    public function test_it_rejects_blank_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('courseId filter must not be blank when provided.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_malformed_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('courseId filter must be a valid ULID.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_blank_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deckId filter must not be blank when provided.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }

    public function test_it_rejects_malformed_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deckId filter must be a valid ULID.');

        app(ListStudyNewCardQueueAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: 'not-a-ulid',
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidLimitProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'over max' => [NewCardQueueLimits::PAGE_SIZE_MAX + 1],
        ];
    }
}
