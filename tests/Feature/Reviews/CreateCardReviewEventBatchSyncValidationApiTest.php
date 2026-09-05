<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardReviewEventBatchSyncValidationApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_accepts_sync_metadata_ids_at_the_column_limit(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $clientEventId = str_repeat('a', SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH);
        $deviceId = str_repeat('b', SyncMetadata::MAX_DEVICE_ID_LENGTH);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => $clientEventId,
                    'device_id' => $deviceId,
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.client_event_id', $clientEventId)
            ->assertJsonPath('data.0.device_id', $deviceId);

        $this->assertDatabaseHas('card_review_events', [
            'client_event_id' => $clientEventId,
            'device_id' => $deviceId,
        ]);
    }
}
