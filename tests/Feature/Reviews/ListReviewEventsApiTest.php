<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListReviewEventsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_review_events_for_the_authenticated_user_across_cards(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $otherUser = User::factory()->create();

        $firstEvent = CardReviewEvent::factory()->for($firstCard)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now()->subDay(),
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
                        'rating',
                        'reviewed_at',
                        'client_event_id',
                        'device_id',
                        'client_created_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstEvent->id,
                'card_id' => $firstCard->id,
                'rating' => CardReviewRating::Hard->value,
                'reviewed_at' => $firstEvent->reviewed_at->toJSON(),
                'client_event_id' => 'event-1',
                'device_id' => 'device-a',
                'client_created_at' => $firstEvent->client_created_at->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondEvent->id,
                'card_id' => $secondCard->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => $secondEvent->reviewed_at->toJSON(),
                'client_event_id' => 'event-2',
                'device_id' => 'device-a',
                'client_created_at' => $secondEvent->client_created_at->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherEvent->id,
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

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(3)->for($card)->create();

        $response = $this->getJson('/api/card-review-events?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $nextUrl = $response->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '2');

        $this->getJson($nextUrl)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        CardReviewEvent::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($card)->create();

        $response = $this->getJson('/api/card-review-events?per_page='.CursorPagination::MAX_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?per_page='.(CursorPagination::MAX_PAGE_SIZE + 1));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?per_page=0');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?per_page=-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/card-review-events?per_page=abc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/card-review-events');

        $response->assertUnauthorized();
    }
}
