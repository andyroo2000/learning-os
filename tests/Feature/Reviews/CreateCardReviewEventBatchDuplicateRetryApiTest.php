<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardReviewEventBatchDuplicateRetryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_idempotent_for_matching_duplicate_client_events_in_the_same_batch(): void
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
            ->assertJsonPath('data.0.id', $response->json('data.1.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_mismatched_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $event = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $this->postJson('/api/card-review-events/batch', [
            'events' => [$event, [...$event, 'rating' => CardReviewRating::Easy->value]],
        ])->assertConflict();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
