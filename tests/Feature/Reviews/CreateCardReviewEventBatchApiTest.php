<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardBatchResult;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Values\SyncMetadata;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CreateCardReviewEventBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_card_review_events_in_a_batch(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $firstDeck = Deck::factory()->for($course)->for($user)->create();
        $secondDeck = Deck::factory()->for($course)->for($user)->create();
        $firstCard = Card::factory()->for($firstDeck)->create();
        $secondCard = Card::factory()->for($secondDeck)->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.card_id', $firstCard->id)
            ->assertJsonPath('data.0.deck_id', $firstDeck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.1.card_id', $secondCard->id)
            ->assertJsonPath('data.1.deck_id', $secondDeck->id)
            ->assertJsonPath('data.1.course_id', $course->id)
            ->assertJsonPath('data.1.rating', CardReviewRating::Easy->value)
            ->assertJsonPath('data.1.client_event_id', 'event-456')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'card_id',
                        'deck_id',
                        'course_id',
                        'rating',
                        'reviewed_at',
                        'client_event_id',
                        'device_id',
                        'client_created_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.0.id')));
        $this->assertTrue(Str::isUlid($response->json('data.1.id')));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'card_id' => $firstCard->id,
            'rating' => CardReviewRating::Good->value,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.1.id'),
            'card_id' => $secondCard->id,
            'rating' => CardReviewRating::Easy->value,
            'client_event_id' => 'event-456',
            'device_id' => 'device-abc',
        ]);
    }

    public function test_it_normalizes_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'id' => '  '.strtoupper($id).'  ',
                        'card_id' => '  '.strtoupper($card->id).'  ',
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }

    public function test_it_trims_client_sync_metadata_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => '  good  ',
                        'reviewed_at' => '  2026-05-27T09:15:00Z  ',
                        'client_event_id' => '  event-123  ',
                        'device_id' => '  device-abc  ',
                        'client_created_at' => '  2026-05-27T09:14:00Z  ',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.0.device_id', 'device-abc')
            ->assertJsonPath('data.0.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_trims_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'id' => "  {$id}  ",
                        'card_id' => "  {$card->id}  ",
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }

    public function test_it_lowercases_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'id' => strtoupper($id),
                        'card_id' => strtoupper($card->id),
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }

    public function test_it_is_idempotent_for_retried_client_events(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $payload = [
            'events' => [
                [
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
        $secondResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstResponse->json('data.0.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.000000Z');

        $this->assertDatabaseCount('card_review_events', 1);
    }

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

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_provided_ulid_collisions_for_other_users(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'other-event',
            'device_id' => 'other-device',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_same_user_provided_ulid_collisions_with_different_sync_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_rejects_claiming_same_user_event_without_sync_metadata_by_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($card)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
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

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_other_user_event_without_sync_metadata_by_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $id,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_hides_provided_ulid_collisions_in_multi_card_batches(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $conflictingId = strtolower((string) Str::ulid());
        $newId = strtolower((string) Str::ulid());
        $otherCard = Card::factory()->create();

        CardReviewEvent::factory()->for($otherCard)->create([
            'id' => $conflictingId,
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'other-event',
            'device_id' => 'other-device',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $conflictingId,
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => $newId,
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseMissing('card_review_events', ['id' => $newId]);
    }

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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertStringContainsString('["device-abc","event-123"]', $response->json('errors.events.0'));
        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_same_batch_sync_key_duplicates_with_different_cards(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertStringContainsString('["device-abc","event-123"]', $response->json('errors.events.0'));
        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_same_batch_provided_ulid_duplicates_with_different_sync_keys(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'id' => $id,
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => strtoupper($id),
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertStringContainsString($id, $response->json('errors.events.0'));
        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_uses_a_provided_ulid_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'id' => strtoupper($id),
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
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Easy->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Easy->value);

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', ['id' => $id]);
    }

    public function test_it_normalizes_provided_ulid_case_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events/batch', [
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
                [
                    'id' => $id,
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
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_returns_created_when_a_retried_batch_also_creates_events(): void
    {
        $user = $this->signIn();
        $existingCard = $this->cardFor($user);
        $newCard = $this->cardFor($user);

        $firstResponse = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $existingCard->id,
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
                    'card_id' => $existingCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
                [
                    'card_id' => $newCard->id,
                    'rating' => CardReviewRating::Hard->value,
                    'reviewed_at' => '2026-05-27T09:25:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:24:00Z',
                ],
            ],
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $eventsByClientEventId = collect($secondResponse->json('data'))->keyBy('client_event_id');

        $this->assertSame($firstResponse->json('data.0.id'), $eventsByClientEventId['event-123']['id']);
        $this->assertSame(CardReviewRating::Good->value, $eventsByClientEventId['event-123']['rating']);
        $this->assertSame($newCard->id, $eventsByClientEventId['event-456']['card_id']);
        $this->assertSame(CardReviewRating::Hard->value, $eventsByClientEventId['event-456']['rating']);

        $this->assertDatabaseCount('card_review_events', 2);
    }

    public function test_it_is_idempotent_for_duplicate_client_events_in_the_same_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
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
            ->assertCreated()
            ->assertJsonPath('data.0.id', $response->json('data.1.id'))
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.1.rating', CardReviewRating::Good->value);

        $this->assertDatabaseCount('card_review_events', 1);
    }

    public function test_it_uses_bulk_lookup_queries_for_many_events(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cards = Card::factory()->count(10)->for($deck)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => $cards
                ->values()
                ->map(fn (Card $card, int $index): array => [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => "event-{$index}",
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ])
                ->all(),
        ]);

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response
            ->assertCreated()
            ->assertJsonCount(10, 'data');

        $selectQueries = $queries->filter(
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'),
        );

        // Auth, ownership, card lookup, and idempotency stay bounded for large batches.
        $this->assertLessThanOrEqual(4, $selectQueries->count());
        $this->assertDatabaseCount('card_review_events', 10);
    }

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
                'events.0.client_event_id',
                'events.0.device_id',
                'events.0.client_created_at',
            ]);

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

    public function test_it_rejects_missing_cards(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => strtolower((string) Str::ulid()),
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
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_another_users_cards(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $otherCard->id,
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
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_cards_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_card_in_a_soft_deleted_deck_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedDeckCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_soft_deleted_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $validCard = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);

        $deletedCard->delete();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $validCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $deletedCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_only_the_unowned_card_in_a_mixed_batch(): void
    {
        $user = $this->signIn();
        $ownedCard = $this->cardFor($user);
        $otherCard = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $ownedCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
                [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Easy->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'client_event_id' => 'event-456',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:19:00Z',
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.1.card_id'])
            ->assertJsonMissingValidationErrors(['events.0.card_id']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_rejects_an_empty_batch(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_returns_a_batch_level_validation_error_when_the_action_rejects_the_batch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->mock(ReviewCardBatchAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new InvalidArgumentException('Batch invariant failed.'));

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events'])
            ->assertJsonPath('errors.events.0', 'Batch invariant failed.');

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->postJson('/api/card-review-events/batch', [
            'events' => [
                [
                    'card_id' => $card->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'client_event_id' => 'event-123',
                    'device_id' => 'device-abc',
                    'client_created_at' => '2026-05-27T09:14:00Z',
                ],
            ],
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_review_events', 0);
    }
}
