<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchDuplicateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_a_provided_ulid_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

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
                    'id' => strtoupper($id),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T05:15:00-04:00',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T05:14:00-04:00',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', ['id' => $id]);
    }

    public function test_it_normalizes_provided_ulid_case_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => strtoupper($id),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T05:15:00-04:00',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T05:14:00-04:00',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

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
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T05:15:00-04:00',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T05:14:00-04:00',
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
}
