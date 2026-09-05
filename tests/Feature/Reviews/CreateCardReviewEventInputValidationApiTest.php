<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;

class CreateCardReviewEventInputValidationApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => "  {$card->id}  ",
            'rating' => '  hard  ',
            'reviewed_at' => '  2026-05-27T09:15:00Z  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.rating', CardReviewRating::Hard->value);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events', [
            'id' => 'not-a-ulid',
            'card_id' => 'also-not-a-ulid',
            'rating' => 'medium',
            'reviewed_at' => 'not-a-date',
            'duration_ms' => ReviewCardData::MAX_DURATION_MS + 1,
            'client_created_at' => 'also-not-a-date',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'card_id', 'rating', 'reviewed_at', 'duration_ms', 'client_created_at']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_non_strict_review_timestamps(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        foreach (['2026-05-27T09:15:00', 'tomorrow', 1780668900, ['2026-05-27T09:15:00Z']] as $reviewedAt) {
            $this->postJson('/api/card-review-events', [
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => $reviewedAt,
                'client_event_id' => 'event-123',
                'device_id' => 'device-abc',
                'client_created_at' => '2026-05-27T09:14:00Z',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reviewed_at']);
        }

        foreach (['2026-05-27T09:14:00', 'tomorrow', 1780668840, ['2026-05-27T09:14:00Z']] as $clientCreatedAt) {
            $this->postJson('/api/card-review-events', [
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
                'client_event_id' => 'event-123',
                'device_id' => 'device-abc',
                'client_created_at' => $clientCreatedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['client_created_at']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_malformed_duration_ms(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        foreach ([['duration_ms' => -1], ['duration_ms' => ['1250']]] as $payload) {
            $response = $this->postJson('/api/card-review-events', [
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
                ...$payload,
            ]);

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['duration_ms']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events', [
            'id' => [strtolower((string) Str::ulid())],
            'card_id' => [strtolower((string) Str::ulid())],
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_partial_client_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'client_created_at']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_blank_client_sync_metadata_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
                'client_event_id' => '   ',
                'device_id' => '   ',
                'client_created_at' => '  2026-05-27T09:14:00Z  ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_event_id', 'device_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_sync_metadata_ids_above_the_column_limit(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => str_repeat('a', SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH + 1),
            'device_id' => str_repeat('b', SyncMetadata::MAX_DEVICE_ID_LENGTH + 1),
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_event_id', 'device_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
