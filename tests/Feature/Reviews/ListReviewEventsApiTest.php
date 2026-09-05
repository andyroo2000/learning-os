<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class ListReviewEventsApiTest extends ListReviewEventsApiTestCase
{
    public function test_it_lists_review_events_for_the_authenticated_user_across_cards(): void
    {
        [$course, $firstDeck, $secondDeck, $firstCard, $secondCard, $otherUser] = $this->listingCards();

        $firstEvent = CardReviewEvent::factory()->for($firstCard)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now()->subDay(),
            'duration_ms' => 1200,
            'client_event_id' => 'event-1',
            'device_id' => 'device-a',
            'client_created_at' => now()->subDay()->subMinute(),
        ]);
        $secondEvent = CardReviewEvent::factory()->for($secondCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now(),
            'client_event_id' => 'event-2',
            'device_id' => 'device-a',
            'client_created_at' => now()->subMinute(),
        ]);
        $otherEvent = $this->cardReviewEventFor($otherUser);

        $response = $this->getJson('/api/card-review-events');

        $this->assertListResponseStructure($response, $firstEvent, $secondEvent);
        $response
            ->assertJsonFragment([
                'id' => $firstEvent->id,
                'card_id' => $firstCard->id,
                'deck_id' => $firstDeck->id,
                'course_id' => $course->id,
                'rating' => CardReviewRating::Hard->value,
                'reviewed_at' => $firstEvent->reviewed_at->toJSON(),
                'duration_ms' => 1200,
                'client_event_id' => 'event-1',
                'device_id' => 'device-a',
                'client_created_at' => $firstEvent->client_created_at->toJSON(),
                'card_state_before' => null,
                'scheduler_state_before' => null,
                'scheduler_state_after' => null,
            ]);
        $response
            ->assertJsonFragment([
                'id' => $secondEvent->id,
                'card_id' => $secondCard->id,
                'deck_id' => $secondDeck->id,
                'course_id' => $course->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => $secondEvent->reviewed_at->toJSON(),
                'client_event_id' => 'event-2',
                'device_id' => 'device-a',
                'client_created_at' => $secondEvent->client_created_at->toJSON(),
                'card_state_before' => null,
                'scheduler_state_before' => null,
                'scheduler_state_after' => null,
            ])
            ->assertJsonMissing([
                'id' => $otherEvent->id,
            ]);
    }

    /** @return array{Course, Deck, Deck, Card, Card, User} */
    private function listingCards(): array
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $firstDeck = Deck::factory()->for($course)->for($user)->create();
        $secondDeck = Deck::factory()->for($course)->for($user)->create();

        return [
            $course,
            $firstDeck,
            $secondDeck,
            Card::factory()->for($firstDeck)->create(),
            Card::factory()->for($secondDeck)->create(),
            User::factory()->create(),
        ];
    }

    private function assertListResponseStructure(
        TestResponse $response,
        CardReviewEvent $firstEvent,
        CardReviewEvent $secondEvent,
    ): void {
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondEvent->id)
            ->assertJsonPath('data.1.id', $firstEvent->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'card_id',
                        'deck_id',
                        'course_id',
                        'rating',
                        'reviewed_at',
                        'duration_ms',
                        'client_event_id',
                        'device_id',
                        'client_created_at',
                        'card_state_before',
                        'scheduler_state_before',
                        'scheduler_state_after',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_review_events(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherEvent = $this->cardReviewEventFor($otherUser);

        $response = $this->getJson('/api/card-review-events');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ])
            ->assertJsonMissing([
                'id' => $otherEvent->id,
            ]);
    }

    public function test_it_returns_empty_results_for_a_card_id_owned_by_another_user(): void
    {
        $this->signIn();
        $otherUserCard = $this->cardFor(User::factory()->create());
        $otherUserEvent = CardReviewEvent::factory()->for($otherUserCard)->create();

        $response = $this->getJson("/api/card-review-events?card_id={$otherUserCard->id}");

        $this->assertEmptyFilterResponse($response, $otherUserEvent);
    }

    public function test_it_returns_empty_results_for_a_deck_id_owned_by_another_user(): void
    {
        $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherUserEvent = CardReviewEvent::factory()->for($otherDeckCard)->create();

        $response = $this->getJson("/api/card-review-events?deck_id={$otherDeck->id}");

        $this->assertEmptyFilterResponse($response, $otherUserEvent);
    }

    private function assertEmptyFilterResponse(TestResponse $response, CardReviewEvent $excludedEvent): void
    {
        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $excludedEvent->id,
            ]);
    }

    public function test_it_excludes_review_events_for_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $visibleEvent = $this->cardReviewEventFor($user);
        $deletedCard = $this->cardFor($user);
        $deletedCardEvent = CardReviewEvent::factory()->for($deletedCard)->create();

        $deletedCard->delete();

        $response = $this->getJson('/api/card-review-events');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleEvent->id)
            ->assertJsonMissing([
                'id' => $deletedCardEvent->id,
            ]);
    }

    public function test_it_excludes_review_events_for_cards_in_soft_deleted_decks(): void
    {
        $user = $this->signIn();
        $visibleEvent = $this->cardReviewEventFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        $deletedDeckEvent = CardReviewEvent::factory()->for($deletedDeckCard)->create();

        $deletedDeck->delete();

        // Reset the deck-delete cascade to test deck-level exclusion independently.
        DB::table('cards')
            ->where('id', $deletedDeckCard->id)
            ->update(['deleted_at' => null]);

        $response = $this->getJson('/api/card-review-events');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleEvent->id)
            ->assertJsonMissing([
                'id' => $deletedDeckEvent->id,
            ]);
    }
}
