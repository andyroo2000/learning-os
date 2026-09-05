<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchMetadataValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => [strtolower((string) Str::ulid())],
                    'card_id' => [strtolower((string) Str::ulid())],
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
            ->assertJsonValidationErrors(['events.0.id', 'events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_blank_sync_metadata_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => '   ',
                        'device_id' => '   ',
                        'client_created_at' => '   ',
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

    public function test_it_rejects_sync_metadata_ids_above_the_column_limit(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => str_repeat('a', SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH + 1),
                    'device_id' => str_repeat('b', SyncMetadata::MAX_DEVICE_ID_LENGTH + 1),
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'events.0.client_event_id',
                'events.0.device_id',
            ]);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
