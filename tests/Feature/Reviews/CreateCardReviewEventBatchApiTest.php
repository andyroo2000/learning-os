<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_card_review_events_in_a_batch(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.card_id', $firstCard->id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.1.card_id', $secondCard->id)
            ->assertJsonPath('data.1.rating', CardReviewRating::Easy->value)
            ->assertJsonPath('data.1.client_event_id', 'event-456')
            ->assertJsonStructure([
                'data' => [
                    [
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
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.0.id')));
        $this->assertTrue(Str::isUlid($response->json('data.1.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.1.id'),
            'card_id' => $secondCard->id,
            'rating' => CardReviewRating::Easy->value,
            'client_event_id' => 'event-456',
            'device_id' => 'device-abc',
        ]);
    }

    public function test_it_is_idempotent_for_retried_client_events(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $payload = [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ];

        $firstResponse = $this->postJson('/api/card-review-events/batch', $payload);
        $secondResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstResponse->json('data.0.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_created_when_a_retried_batch_also_creates_events(): void
    {
        $user = $this->signIn();
        $existingCard = $this->cardFor($user);
        $newCard = $this->cardFor($user);

        $firstResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $existingCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $secondResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $existingCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
                [
                    'card_id' => $newCard->id,
                    'rating' => CardReviewRating::Hard->value,
                    'reviewed_at' => '2026-05-27T09:25:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:24:00Z',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $eventsByClientEventId = collect($secondResponse->json('data'))->keyBy('client_event_id');

        $this->assertSame($firstResponse->json('data.0.id'), $eventsByClientEventId['event-123']['id']);
        $this->assertSame(CardReviewRating::Good->value, $eventsByClientEventId['event-123']['rating']);
        $this->assertSame($newCard->id, $eventsByClientEventId['event-456']['card_id']);
        $this->assertSame(CardReviewRating::Hard->value, $eventsByClientEventId['event-456']['rating']);

        $this->assertDatabaseCount('card_review_events', 2);
    }

    public function test_it_is_idempotent_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $response->json('data.1.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_bulk_lookup_queries_for_many_events(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cards = Card::factory()->count(10)->for($deck)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => $cards
                ->values()
                ->map(fn (Card $card, int $index): array => [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => "event-{$index}",
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ])
                ->all(),
        ]);

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response
            ->assertCreated()
            ->assertJsonCount(10, 'data');

        $selectQueries = $queries->filter(
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'),
        );

        // Auth, ownership, card lookup, and idempotency stay bounded for large batches.
        $this->assertLessThanOrEqual(4, $selectQueries->count());
        $this->assertDatabaseCount('card_review_events', 10);
    }

    public function test_it_requires_sync_metadata_for_each_event(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'events.0.client_event_id',
                'events.0.device_id',
                'events.0.client_created_at',
            ]);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_invalid_batch_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => 'not-a-ulid',
                    'card_id' => 'also-not-a-ulid',
                    'rating' => 'medium',
                    'reviewed_at' => 'not-a-date',
                    'client_event_id' => '',
                    'device_id' => '',
                    'client_created_at' => 'also-not-a-date',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'events.0.id',
                'events.0.card_id',
                'events.0.rating',
                'events.0.reviewed_at',
                'events.0.client_event_id',
                'events.0.device_id',
                'events.0.client_created_at',
            ]);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_missing_cards(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => strtolower((string) Str::ulid()),
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_another_users_cards(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_cards_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_card_in_a_soft_deleted_deck_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedDeckCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_soft_deleted_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);

        $deletedCard->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_unowned_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $ownedCard = $this->cardFor($user);
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $ownedCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_an_empty_batch(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
