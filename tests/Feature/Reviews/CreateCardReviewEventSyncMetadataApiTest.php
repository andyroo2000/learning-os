<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class CreateCardReviewEventSyncMetadataApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_trims_client_sync_metadata_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'card_id' => $card->id,
                'rating' => '  good  ',
                'reviewed_at' => '  2026-05-27T09:15:00Z  ',
                'client_event_id' => '  event-123  ',
                'device_id' => '  device-abc  ',
                'client_created_at' => '  2026-05-27T09:14:00Z  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.client_event_id', 'event-123')
            ->assertJsonPath('data.device_id', 'device-abc')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_accepts_sync_metadata_ids_at_the_column_limit(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $clientEventId = str_repeat('a', SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH);
        $deviceId = str_repeat('b', SyncMetadata::MAX_DEVICE_ID_LENGTH);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => $clientEventId,
            'device_id' => $deviceId,
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.client_event_id', $clientEventId)
            ->assertJsonPath('data.device_id', $deviceId);

        $this->assertDatabaseHas('card_review_events', [
            'client_event_id' => $clientEventId,
            'device_id' => $deviceId,
        ]);
    }

    public function test_it_is_idempotent_for_the_same_canonical_payload(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $payload = [
            'card_id' => strtoupper($card->id),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);

        $secondResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T05:15:00-04:00',
            'duration_ms' => '1250',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T05:14:00-04:00',
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'))
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    #[DataProvider('reviewTimestampPrecisionProvider')]
    public function test_it_normalizes_review_timestamps_to_database_precision_on_create_and_replay(
        string $reviewedAt,
        string $replayedAt,
        string $expectedJson,
        string $expectedDatabase,
    ): void {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => $reviewedAt,
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', [
            ...$payload,
            'reviewed_at' => $replayedAt,
        ]);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.reviewed_at', $expectedJson);
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.reviewed_at', $expectedJson);
        $this->assertSame($firstResponse->json('data.reviewed_at'), $secondResponse->json('data.reviewed_at'));
        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'reviewed_at' => $expectedDatabase,
        ]);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_normalizes_sync_metadata_timestamp_precision_on_create_and_replay(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $payload = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'precision-event',
            'device_id' => 'precision-device',
            'client_created_at' => '2026-05-27T05:14:00.987654-04:00',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.987000Z');
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'))
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.987000Z');
        $this->assertDatabaseHas('card_review_events', [
            'client_event_id' => 'precision-event',
            'client_created_at' => '2026-05-27 09:14:00.987',
        ]);
        $this->assertDatabaseCount('card_review_events', 1);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function reviewTimestampPrecisionProvider(): array
    {
        return [
            'no fractional digits' => ['2026-05-27T09:15:00Z', '2026-05-27T09:15:00Z', '2026-05-27T09:15:00.000000Z', '2026-05-27 09:15:00'],
            'one fractional digit' => ['2026-05-27T09:15:00.1Z', '2026-05-27T09:15:00.1Z', '2026-05-27T09:15:00.100000Z', '2026-05-27 09:15:00.100'],
            'two fractional digits' => ['2026-05-27T09:15:00.12Z', '2026-05-27T09:15:00.12Z', '2026-05-27T09:15:00.120000Z', '2026-05-27 09:15:00.120'],
            'three fractional digits' => ['2026-05-27T09:15:00.123Z', '2026-05-27T09:15:00.123Z', '2026-05-27T09:15:00.123000Z', '2026-05-27 09:15:00.123'],
            'more than three fractional digits' => ['2026-05-27T09:15:00.123111Z', '2026-05-27T09:15:00.123999Z', '2026-05-27T09:15:00.123000Z', '2026-05-27 09:15:00.123'],
            'explicit offset' => ['2026-05-27T04:45:00.987111-04:30', '2026-05-27T09:15:00.987999Z', '2026-05-27T09:15:00.987000Z', '2026-05-27 09:15:00.987'],
        ];
    }
}
