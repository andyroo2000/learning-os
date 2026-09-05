<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Support\Str;

class CreateCardReviewEventSyncIdentityApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_rejects_provided_ulid_retries_that_omit_existing_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $firstResponse = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $secondResponse = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_retryable_response_when_review_event_race_recovery_fails(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardAction
        {
            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::retryableConflict();
            }
        });

        $response = $this->postJson('/api/card-review-events', [
            'id' => strtolower((string) Str::ulid()),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Card review event ID conflict could not be resolved; retry the request.')
            ->assertJsonPath('reason', 'card_review_event_retry');
    }

    public function test_it_requires_a_stable_identity_for_equal_timestamp_resubmissions(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $payload = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.rating', CardReviewRating::Good->value);
        $secondResponse
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Equal-timestamp review events require an explicit id or complete sync metadata.',
            )
            ->assertJsonPath('reason', 'card_review_event_identity_required');

        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_it_rejects_equal_timestamp_sync_metadata_when_the_existing_event_has_no_sync_identity(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $basePayload = [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $basePayload);
        $secondResponse = $this->postJson('/api/card-review-events', [
            ...$basePayload,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.client_event_id', null)
            ->assertJsonPath('data.device_id', null)
            ->assertJsonPath('data.client_created_at', null);
        $secondResponse
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Equal-timestamp review events require an explicit id or complete sync metadata.',
            )
            ->assertJsonPath('reason', 'card_review_event_identity_required');

        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }
}
