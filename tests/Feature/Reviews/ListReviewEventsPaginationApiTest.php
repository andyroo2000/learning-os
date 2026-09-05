<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Pagination\CursorPagination;
use Illuminate\Testing\TestResponse;

class ListReviewEventsPaginationApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $sharedReviewedAt = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            CardReviewEvent::factory()->for($card)->create([
                'rating' => CardReviewRating::Good,
                'reviewed_at' => now()->subMinutes($index),
            ]);
        }

        // Explicit neighboring ULIDs keep the reviewed_at tie deterministic.
        $lowTieEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pc',
            'reviewed_at' => $sharedReviewedAt,
        ]);
        $highTieEvent = CardReviewEvent::factory()->for($card)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pd',
            'reviewed_at' => $sharedReviewedAt,
        ]);

        $firstPage = $this->getJson('/api/card-review-events');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieEvent->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/card-review-events?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieEvent->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_preserves_course_id_filter_when_following_a_cursor(): void
    {
        [$course, $card, $otherCard] = $this->courseFilterCards();
        $olderEvent = CardReviewEvent::factory()->for($card)->create([
            'reviewed_at' => now()->subMinutes(2),
        ]);
        $newerEvent = CardReviewEvent::factory()->for($card)->create([
            'reviewed_at' => now()->subMinute(),
        ]);
        $otherCourseEvent = CardReviewEvent::factory()->for($otherCard)->create([
            'reviewed_at' => now(),
        ]);

        $firstPage = $this->getJson("/api/card-review-events?course_id={$course->id}&per_page=1");

        $nextUrl = $firstPage->json('links.next');

        $this->assertFirstCoursePage($firstPage, $newerEvent, $nextUrl, $course);

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $this->assertSecondCoursePage($secondPage, $olderEvent, $otherCourseEvent);
    }

    private function assertFirstCoursePage(
        TestResponse $firstPage,
        CardReviewEvent $newerEvent,
        string $nextUrl,
        Course $course,
    ): void {
        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerEvent->id);
        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'course_id', $course->id);
    }

    private function assertSecondCoursePage(
        TestResponse $secondPage,
        CardReviewEvent $olderEvent,
        CardReviewEvent $otherCourseEvent,
    ): void {
        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderEvent->id)
            ->assertJsonMissing([
                'id' => $otherCourseEvent->id,
            ]);
    }

    public function test_it_preserves_card_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = $this->cardFor($user);
        $events = $this->cursorFilterEvents($card, $otherCard);

        $this->assertFilterPersistsAcrossCursor('card_id', $card->id, $events);
    }

    public function test_it_preserves_deck_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherCard = Card::factory()->for($otherDeck)->create();
        $events = $this->cursorFilterEvents($card, $otherCard);

        $this->assertFilterPersistsAcrossCursor('deck_id', $deck->id, $events);
    }

    /** @return array{older: CardReviewEvent, newer: CardReviewEvent, excluded: CardReviewEvent} */
    private function cursorFilterEvents(Card $card, Card $excludedCard): array
    {
        return [
            'older' => CardReviewEvent::factory()->for($card)->create([
                'reviewed_at' => now()->subMinutes(2),
            ]),
            'newer' => CardReviewEvent::factory()->for($card)->create([
                'reviewed_at' => now()->subMinute(),
            ]),
            'excluded' => CardReviewEvent::factory()->for($excludedCard)->create([
                'reviewed_at' => now(),
            ]),
        ];
    }

    /** @param array{older: CardReviewEvent, newer: CardReviewEvent, excluded: CardReviewEvent} $events */
    private function assertFilterPersistsAcrossCursor(string $filter, string $id, array $events): void
    {
        $firstPage = $this->getJson("/api/card-review-events?{$filter}={$id}&per_page=1");
        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $events['newer']->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, $filter, $id);

        $this->getJson($this->pathAndQueryFromUrl($nextUrl))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $events['older']->id)
            ->assertJsonMissing([
                'id' => $events['excluded']->id,
            ]);
    }

    /** @return array{Course, Card, Card} */
    private function courseFilterCards(): array
    {
        [$course, $deck, $otherDeck] = $this->courseFilterDecks();

        return [
            $course,
            Card::factory()->for($deck)->create(),
            Card::factory()->for($otherDeck)->create(),
        ];
    }

    /** @return array{Course, Deck, Deck} */
    private function courseFilterDecks(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();

        return [
            $course,
            Deck::factory()->for($course)->for($user)->create(),
            Deck::factory()->for($otherCourse)->for($user)->create(),
        ];
    }
}
