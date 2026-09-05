<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchExistingRetryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_event_when_provided_ulid_and_sync_metadata_match(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        $payload = [
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
            ],
        ];

        $firstResponse = $this->postJson('/api/card-review-events/batch', $payload);
        $secondResponse = $this->postJson('/api/card-review-events/batch', $payload);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.0.device_id', 'device-abc');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_sync_metadata_retries_with_a_different_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $firstId = strtolower((string) Str::ulid());
        $secondId = strtolower((string) Str::ulid());

        $firstResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $firstId,
                    'card_id' => $card->id,
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
                    'id' => $secondId,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['id' => $secondId]);
    }
}
