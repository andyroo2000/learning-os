<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\ApplyCardStudyReviewAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Values\SyncMetadata;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card_review_event(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.duration_ms', null)
            ->assertJsonPath('data.card_state_before.study_status', 'new')
            ->assertJsonPath('data.card_state_before.due_at', null)
            ->assertJsonPath('data.scheduler_state_before', null)
            ->assertJsonPath('data.scheduler_state_after.reps', 1)
            ->assertJsonPath('data.scheduler_state_after.state', 2)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'card_id',
                    'deck_id',
                    'course_id',
                    'rating',
                    'reviewed_at',
                    'duration_ms',
                    'client_event_id',
                    'device_id',
                    'client_created_at',
                    'card_state_before',
                    'scheduler_state_before',
                    'scheduler_state_after',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
    }

    public function test_it_reviews_a_legacy_imported_card_with_an_uppercase_ulid(): void
    {
        $user = $this->signIn();
        $storedCardId = strtoupper((string) Str::ulid());
        Card::factory()->for($this->deckFor($user))->create(['id' => $storedCardId]);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => strtolower($storedCardId),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_id', $storedCardId);

        $this->assertSame(
            $storedCardId,
            CardReviewEvent::query()->findOrFail($response->json('data.id'))->card_id,
        );
    }

    public function test_it_stores_client_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'duration_ms' => '1250',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.duration_ms', 1250)
            ->assertJsonPath('data.client_event_id', 'event-123')
            ->assertJsonPath('data.device_id', 'device-abc')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'duration_ms' => 1250,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_stores_review_timestamps_with_explicit_offsets_as_utc(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T05:15:00-04:00',
            'client_event_id' => 'event-offset-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T05:14:00-04:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.id'),
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_rate_limits_review_event_writes_by_user(): void
    {
        $limiter = new CardReviewEventCreateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $thirdCard = $this->cardFor($user);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $restoreCardReviewEventCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so these match the request-derived keys used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ])
                ->assertCreated();

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                ])
                ->assertCreated();

            $this->signIn($otherUser);

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:25:00Z',
                ])
                ->assertCreated();

            $this->signIn($user);

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $thirdCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:30:00Z',
                ])
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '2')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');

            $this->getJson('/api/card-review-events')->assertOk();

            $this->assertSame(2, CardReviewEvent::query()->whereHas('card.deck', fn ($query) => $query->where('user_id', $user->id))->count());
            $this->assertSame(1, CardReviewEvent::query()->whereHas('card.deck', fn ($query) => $query->where('user_id', $otherUser->id))->count());
            $this->assertDatabaseMissing('card_review_events', [
                'card_id' => $thirdCard->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreCardReviewEventCreateLimiter();
        }
    }

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

    public function test_it_is_idempotent_for_the_same_client_event_and_device(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $firstResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $secondResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:20:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:19:00Z',
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'))
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_sync_metadata_retries_with_a_different_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $firstId = strtolower((string) Str::ulid());
        $secondId = strtolower((string) Str::ulid());

        $firstResponse = $this->postJson('/api/card-review-events', [
            'id' => $firstId,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $secondResponse = $this->postJson('/api/card-review-events', [
            'id' => $secondId,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['id' => $secondId]);
    }

    public function test_it_hides_sync_metadata_collisions_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();

        CardReviewEvent::factory()->for($otherCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_existing_event_when_provided_ulid_and_sync_metadata_match(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => strtoupper($id),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27T09:14:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', $payload);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.client_event_id', 'event-123')
            ->assertJsonPath('data.device_id', 'device-abc')
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

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

        $this->app->instance(ReviewCardAction::class, new class(app(RecordSyncFeedEntryAction::class), app(ApplyCardStudyReviewAction::class)) extends ReviewCardAction
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

    public function test_it_creates_distinct_events_for_retries_without_sync_metadata(): void
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
            ->assertCreated()
            ->assertJsonPath('data.rating', CardReviewRating::Good->value);

        $this->assertNotSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        $this->assertDatabaseCount('card_review_events', 2);
    }

    public function test_it_creates_a_distinct_event_when_a_retry_adds_sync_metadata(): void
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
            ->assertCreated()
            ->assertJsonPath('data.client_event_id', 'event-123')
            ->assertJsonPath('data.device_id', 'device-abc')
            // CardReviewEventResource serializes datetimes with microsecond precision.
            ->assertJsonPath('data.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertNotSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        $this->assertDatabaseCount('card_review_events', 2);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_normalizes_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => '  '.strtoupper($id).'  ',
                'card_id' => '  '.strtoupper($card->id).'  ',
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_trims_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => "  {$id}  ",
                'card_id' => "  {$card->id}  ",
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_lowercases_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => strtoupper($id),
                'card_id' => strtoupper($card->id),
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_returns_existing_event_for_client_provided_ulid_retries(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => strtoupper($id),
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ];

        $firstResponse = $this->postJson('/api/card-review-events', $payload);
        $secondResponse = $this->postJson('/api/card-review-events', $payload);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id)
            ->assertJsonPath('data.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_client_provided_ulid_conflicts_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_client_provided_ulid_conflicts_for_other_users_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $otherCard->delete();

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts_for_owned_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());
        CardReviewEvent::factory()->for($deletedCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
        $deletedCard->delete();

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

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

    public function test_it_rejects_missing_card(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => strtolower((string) Str::ulid()),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $otherCard->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
