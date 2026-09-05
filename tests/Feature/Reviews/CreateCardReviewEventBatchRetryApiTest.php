<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateCardReviewEventBatchRetryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_idempotent_for_retried_client_events_with_the_same_canonical_payload(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $payload = [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00.123111Z',
                    'duration_ms' => 1250,
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00.987111Z',
                ],
            ],
        ];

        $firstResponse = $this->postJson('/api/card-review-events/batch', $payload);
        $secondResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T05:15:00.123999-04:00',
                    'duration_ms' => '1250',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T05:14:00.987654-04:00',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstResponse->json('data.0.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.123000Z')
            ->assertJsonPath('data.0.client_created_at', '2026-05-27T09:14:00.987000Z');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', [
            'reviewed_at' => '2026-05-27 09:15:00.123',
            'client_created_at' => '2026-05-27 09:14:00.987',
        ]);
    }

    #[DataProvider('syncPayloadMismatchProvider')]
    public function test_it_rejects_retried_client_events_with_a_different_payload(string $field, mixed $value): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $event = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $this->postJson('/api/card-review-events/batch', ['events' => [$event]])->assertCreated();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [[...$event, $field => $value]],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_reusing_sync_metadata_for_another_card_owned_by_the_same_user(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = Card::factory()->for($firstCard->deck)->create();
        $event = [
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $this->postJson('/api/card-review-events/batch', ['events' => [$event]])->assertCreated();

        $this->postJson('/api/card-review-events/batch', [
            'events' => [[...$event, 'card_id' => $secondCard->id]],
        ])->assertConflict();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public static function syncPayloadMismatchProvider(): array
    {
        return [
            'rating' => ['rating', CardReviewRating::Easy->value],
            'reviewed at' => ['reviewed_at', '2026-05-27T09:20:00Z'],
            'duration' => ['duration_ms', 1251],
            'client created at' => ['client_created_at', '2026-05-27T09:19:00Z'],
        ];
    }
}
