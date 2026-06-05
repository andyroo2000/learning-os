<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListCardsActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

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
            courseId: ' '.strtoupper($course->id).' ',
        );

        $this->assertSame([$courseCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_filters_cards_by_deck_id(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $deckCard = Card::factory()->for($deck)->create();
        Card::factory()->for($otherDeck)->create();
        Card::factory()->for($this->deckFor(User::factory()->create()))->create();

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            deckId: ' '.strtoupper($deck->id).' ',
        );

        $this->assertSame([$deckCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_requires_deck_id_filters_to_match_the_course_filter_when_both_are_provided(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);

        Card::factory()->for($otherCourseDeck)->create();

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            courseId: $course->id,
            deckId: $otherCourseDeck->id,
        );

        $this->assertEmpty($cards->items());
    }

    public function test_it_returns_empty_results_for_a_deck_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);

        Card::factory()->for($otherDeck)->create();

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            deckId: $otherDeck->id,
        );

        $this->assertEmpty($cards->items());
    }

    public function test_it_filters_cards_by_study_status_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::Review);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            studyStatus: ' REVIEW ',
        );

        $this->assertSame([$reviewCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_accepts_study_status_enums_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            studyStatus: CardStudyStatus::Review,
        );

        $this->assertSame([$reviewCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_filters_cards_by_card_type_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $productionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Production,
        ]);
        Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);
        Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'card_type' => CardType::Production,
        ]);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            cardType: ' PRODUCTION ',
        );

        $this->assertSame([$productionCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_accepts_card_type_enums_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $clozeCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Cloze,
        ]);
        Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            cardType: CardType::Cloze,
        );

        $this->assertSame([$clozeCard->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_filters_cards_by_search_query_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $match = Card::factory()->for($deck)->create([
            'search_text' => 'Photosynthesis makes glucose',
        ]);
        Card::factory()->for($deck)->create([
            'search_text' => 'Cellular respiration releases energy',
        ]);
        Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'search_text' => 'Photosynthesis from another user',
        ]);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            q: ' PHOTO ',
        );

        $this->assertSame([$match->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_treats_search_wildcards_as_literals_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $match = Card::factory()->for($deck)->create([
            'search_text' => 'Recall 100% of deck_1',
        ]);
        Card::factory()->for($deck)->create([
            'search_text' => 'Recall 100 percent of deckA1',
        ]);

        $cards = app(ListCardsAction::class)->handle(
            userId: $user->id,
            q: '100% of deck_1',
        );

        $this->assertSame([$match->id], collect($cards->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_study_status_filters_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status filter must not be blank when provided.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            studyStatus: '   ',
        );
    }

    public function test_it_rejects_blank_search_queries_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card search query filter must not be blank when provided.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            q: '   ',
        );
    }

    public function test_it_rejects_blank_card_type_filters_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type filter must not be blank when provided.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            cardType: '   ',
        );
    }

    public function test_it_rejects_malformed_study_status_filters_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status filter must be one of: new, learning, review, relearning, suspended, buried.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            studyStatus: 'queued',
        );
    }

    public function test_it_rejects_malformed_card_type_filters_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type filter must be one of: recognition, production, cloze.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            cardType: 'reverse',
        );
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

    public function test_it_rejects_blank_deck_id_filters(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card deck_id filter must not be blank when provided.');

        app(ListCardsAction::class)->handle(
            userId: $user->id,
            deckId: '   ',
        );
    }
}
