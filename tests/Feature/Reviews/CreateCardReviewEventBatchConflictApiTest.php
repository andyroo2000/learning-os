<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Results\ReviewCardBatchResult;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchConflictApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_retryable_response_for_batch_conflict_recovery(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardBatchAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardBatchAction
        {
            public function handle(iterable $items): ReviewCardBatchResult
            {
                throw CardReviewEventConflictException::retryableConflict();
            }
        });

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => strtolower((string) Str::ulid()),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Card review event ID conflict could not be resolved; retry the request.')
            ->assertJsonPath('reason', 'card_review_event_retry');
    }

    public function test_it_rejects_same_batch_sync_key_duplicates_with_different_provided_ulids(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => strtolower((string) Str::ulid()),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => strtolower((string) Str::ulid()),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');
        $this->assertDatabaseCount('card_review_events', 0);
    }
}
