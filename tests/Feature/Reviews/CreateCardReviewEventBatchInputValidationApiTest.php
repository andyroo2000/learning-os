<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardReviewEventBatchInputValidationApiTest extends TestCase
{
    use RefreshDatabase;

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
                    'duration_ms' => ReviewCardData::MAX_DURATION_MS + 1,
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
                'events.0.duration_ms',
                'events.0.client_event_id',
                'events.0.device_id',
                'events.0.client_created_at',
            ]);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_non_strict_batch_review_timestamps(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        foreach (['2026-05-27T09:15:00', 'tomorrow', 1780668900, ['2026-05-27T09:15:00Z']] as $reviewedAt) {
            $this->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => $reviewedAt,
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['events.0.reviewed_at']);
        }

        foreach (['2026-05-27T09:14:00', 'tomorrow', 1780668840, ['2026-05-27T09:14:00Z']] as $clientCreatedAt) {
            $this->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => $clientCreatedAt,
                    ],
                ],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['events.0.client_created_at']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_malformed_batch_duration_ms(): void
    {
        $this->signIn();
        $card = Card::factory()->create();

        foreach ([['duration_ms' => -1], ['duration_ms' => ['1250']]] as $payload) {
            $response = $this->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                        ...$payload,
                    ],
                ],
            ]);

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['events.0.duration_ms']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
