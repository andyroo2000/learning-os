<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\AssertsCardReviewEventSyncFeedEntries;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class UndoStudyReviewCompatibilityApiTest extends TestCase
{
    use AssertsCardReviewEventSyncFeedEntries;
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create();

        $this->deleteJson("/api/study/reviews/{$reviewEvent->id}")
            ->assertUnauthorized();

        $this->postJson('/api/study/reviews/undo', [
            'reviewLogId' => $reviewEvent->id,
        ])->assertUnauthorized();
    }

    public function test_it_undoes_a_study_review_with_a_convolab_compatible_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            $card = $this->cardFor($user, [
                'study_status' => CardStudyStatus::Learning,
                'new_queue_position' => 4,
                'scheduler_state' => [
                    'state' => 1,
                    'reps' => 2,
                ],
                'due_at' => '2026-05-28T09:15:00Z',
                'introduced_at' => '2026-05-20T09:15:00Z',
                'failed_at' => '2026-05-24T09:15:00Z',
                'last_reviewed_at' => '2026-05-25T09:15:00Z',
            ]);

            $createResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => CardReviewRating::Good->value,
            ]);
            $reviewLogId = $createResponse->json('reviewLogId');
            $reviewEvent = CardReviewEvent::query()->findOrFail($reviewLogId);
            $reviewCardEntry = $this->assertCardSyncPayloadRecorded(
                $card->refresh()->load('deck'),
                SyncFeedOperation::Update,
            );

            $response = $this->deleteJson('/api/study/reviews/'.strtoupper($reviewLogId), [
                'timeZone' => 'America/New_York',
                'currentOverview' => [
                    'reviewCount' => 1,
                ],
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('reviewLogId', $reviewLogId)
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.state.queueState', 'learning')
                ->assertJsonPath('card.state.dueAt', '2026-05-28T09:15:00.000000Z')
                ->assertJsonPath('card.state.scheduler.state', 1)
                ->assertJsonPath('overview.learningCount', 1)
                ->assertJsonPath('overview.reviewCount', 0);

            $this->assertDatabaseMissing('card_review_events', ['id' => $reviewLogId]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'learning',
                'new_queue_position' => 4,
                'due_at' => '2026-05-28 09:15:00',
            ]);
            $this->assertReviewUndoSyncPayloads($reviewEvent, $card, $reviewCardEntry->checkpoint);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_undoes_a_study_review_through_the_legacy_post_body_alias(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            $card = $this->cardFor($user, [
                'study_status' => CardStudyStatus::Learning,
                'new_queue_position' => 4,
                'scheduler_state' => [
                    'state' => 1,
                    'reps' => 2,
                ],
                'due_at' => '2026-05-28T09:15:00Z',
                'introduced_at' => '2026-05-20T09:15:00Z',
                'failed_at' => '2026-05-24T09:15:00Z',
                'last_reviewed_at' => '2026-05-25T09:15:00Z',
            ]);
            $createResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => CardReviewRating::Good->value,
            ]);
            $reviewLogId = $createResponse->json('reviewLogId');
            $reviewEvent = CardReviewEvent::query()->findOrFail($reviewLogId);
            $reviewCardEntry = $this->assertCardSyncPayloadRecorded(
                $card->refresh()->load('deck'),
                SyncFeedOperation::Update,
            );

            $response = $this->postJson('/api/study/reviews/undo', [
                'reviewLogId' => '  '.strtoupper($reviewLogId).'  ',
                'timeZone' => '  America/New_York  ',
                'currentOverview' => [
                    'reviewCount' => 1,
                ],
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('reviewLogId', $reviewLogId)
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.state.queueState', 'learning')
                ->assertJsonPath('overview.learningCount', 1)
                ->assertJsonPath('overview.reviewCount', 0);

            $this->assertDatabaseMissing('card_review_events', ['id' => $reviewLogId]);
            $this->assertReviewUndoSyncPayloads($reviewEvent, $card, $reviewCardEntry->checkpoint);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_study_and_canonical_review_undos_share_the_same_rate_limit_bucket(): void
    {
        $limiter = new CardReviewEventUndoRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $thirdCard = $this->cardFor($user);
        $reviewCard = app(ReviewCardAction::class);

        $firstReviewLogId = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $firstCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: Carbon::parse('2026-06-05T15:30:00Z'),
        ))->reviewEvent->id;
        $secondReviewLogId = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $secondCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: Carbon::parse('2026-06-05T15:35:00Z'),
        ))->reviewEvent->id;
        $thirdReviewLogId = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $thirdCard->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: Carbon::parse('2026-06-05T15:40:00Z'),
        ))->reviewEvent->id;

        $restoreCardReviewEventUndoLimiter = function () use ($limiter): void {
            RateLimiter::for(CardReviewEventUndoRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so this matches the request-derived key used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(CardReviewEventUndoRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(1)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->deleteJson("/api/card-review-events/{$firstReviewLogId}")
                ->assertOk();

            $this
                ->postJson('/api/study/reviews/undo', [
                    'reviewLogId' => $secondReviewLogId,
                ])
                ->assertTooManyRequests();

            $this
                ->deleteJson("/api/study/reviews/{$thirdReviewLogId}")
                ->assertTooManyRequests();

            $this->getJson('/api/card-review-events')->assertOk();

            $this->assertDatabaseMissing('card_review_events', ['id' => $firstReviewLogId]);
            $this->assertDatabaseHas('card_review_events', ['id' => $secondReviewLogId]);
            $this->assertDatabaseHas('card_review_events', ['id' => $thirdReviewLogId]);
        } finally {
            RateLimiter::clear($userKey);
            $restoreCardReviewEventUndoLimiter();
        }
    }

    public function test_it_validates_compatibility_inputs(): void
    {
        $reviewEvent = $this->cardReviewEventFor($this->signIn());

        $this->deleteJson("/api/study/reviews/{$reviewEvent->id}", [
            'timeZone' => 'not-a-zone',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timeZone']);

        $this->deleteJson("/api/study/reviews/{$reviewEvent->id}", [
            'timeZone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timeZone']);

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_validates_legacy_post_body_inputs(): void
    {
        $this->signIn();

        $this->postJson('/api/study/reviews/undo', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reviewLogId']);

        $this->postJson('/api/study/reviews/undo', [
            'reviewLogId' => 'not-a-ulid',
            'timeZone' => 'not-a-zone',
            'currentOverview' => 'stale',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reviewLogId', 'timeZone', 'currentOverview']);

        $this->postJson('/api/study/reviews/undo', [
            'reviewLogId' => [strtolower((string) str()->ulid())],
            'timeZone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reviewLogId', 'timeZone']);
    }

    public function test_it_returns_not_found_for_missing_or_unowned_review_logs(): void
    {
        $user = $this->signIn();
        $otherUserReviewEvent = $this->cardReviewEventFor(User::factory()->create());
        $deletedCardReviewEvent = $this->cardReviewEventFor($user);
        $deletedDeckReviewEvent = $this->cardReviewEventFor($user);
        $deletedCardReviewEvent->card->delete();
        $deletedDeckReviewEvent->card->deck()->firstOrFail()->delete();

        $this->deleteJson('/api/study/reviews/'.strtolower((string) str()->ulid()))
            ->assertNotFound()
            ->assertJsonPath('message', 'Study review not found.');

        $this->deleteJson("/api/study/reviews/{$otherUserReviewEvent->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Study review not found.');

        $this->deleteJson("/api/study/reviews/{$deletedCardReviewEvent->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Study review not found.');

        $this->deleteJson("/api/study/reviews/{$deletedDeckReviewEvent->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Study review not found.');

        $this->assertDatabaseHas('card_review_events', ['id' => $otherUserReviewEvent->id]);
        $this->assertDatabaseHas('card_review_events', ['id' => $deletedCardReviewEvent->id]);
        $this->assertDatabaseHas('card_review_events', ['id' => $deletedDeckReviewEvent->id]);
    }

    public function test_it_rejects_undoing_a_review_that_is_not_the_latest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn());

            $firstResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-06-05T15:35:00Z'));
            $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
            ])->assertOk();

            $this->deleteJson('/api/study/reviews/'.$firstResponse->json('reviewLogId'))
                ->assertConflict()
                ->assertJsonPath('reason', 'card_review_event_not_latest');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_returns_not_found_on_retry_after_successful_undo(): void
    {
        $card = $this->cardFor($this->signIn());
        $createResponse = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ]);
        $reviewLogId = $createResponse->json('reviewLogId');

        $this->deleteJson("/api/study/reviews/{$reviewLogId}")
            ->assertOk();

        $this->deleteJson("/api/study/reviews/{$reviewLogId}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Study review not found.');
    }

    public function test_it_returns_server_error_when_the_undo_snapshot_is_missing(): void
    {
        $user = $this->signIn();
        $reviewEvent = CardReviewEvent::factory()
            ->for($this->cardFor($user))
            ->create([
                'card_state_before' => null,
            ]);

        $this->deleteJson("/api/study/reviews/{$reviewEvent->id}")
            ->assertStatus(500)
            ->assertJsonPath('reason', 'card_review_event_missing_undo_state');

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_returns_server_error_when_the_undo_snapshot_is_invalid(): void
    {
        $user = $this->signIn();

        foreach ([
            'not-a-date',
            '2026-02-31T09:15:00Z',
            '2026-05-28T09:15:00-13:00',
        ] as $dueAt) {
            $reviewEvent = CardReviewEvent::factory()
                ->for($this->cardFor($user))
                ->create([
                    'card_state_before' => [
                        'study_status' => 'new',
                        'new_queue_position' => null,
                        'scheduler_state' => null,
                        'due_at' => $dueAt,
                        'introduced_at' => null,
                        'failed_at' => null,
                        'last_reviewed_at' => null,
                    ],
                ]);

            $this->deleteJson("/api/study/reviews/{$reviewEvent->id}")
                ->assertStatus(500)
                ->assertJsonPath('reason', 'card_review_event_invalid_undo_state');

            $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
        }
    }

    private function assertReviewUndoSyncPayloads(CardReviewEvent $reviewEvent, Card $card, int $afterCheckpoint): void
    {
        $card->refresh()->load('deck');
        $reviewEvent->setRelation('card', $card);

        $this->assertCardReviewEventDeleteSyncPayloadRecorded($reviewEvent);
        $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Update, afterCheckpoint: $afterCheckpoint);
    }
}
