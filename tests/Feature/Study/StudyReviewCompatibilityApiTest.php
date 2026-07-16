<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
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
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StudyReviewCompatibilityApiTest extends TestCase
{
    use AssertsCardReviewEventSyncFeedEntries;
    use AssertsCardSyncFeedEntries;
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    private const CONVOLAB_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ])->assertUnauthorized();
    }

    public function test_it_records_a_study_review_with_a_convolab_compatible_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $importJob = StudyImportJob::factory()->for($user)->completed()->create([
                'convolab_id' => self::CONVOLAB_IMPORT_ID,
                'source_filename' => 'core-2k.apkg',
            ]);
            $card = $this->cardFor($user, [
                'front_text' => '会社',
                'back_text' => 'company',
                'prompt_json' => ['type' => 'text', 'text' => '会社'],
                'answer_json' => ['type' => 'text', 'text' => 'company'],
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
                'source_note_id' => 501,
                'source_card_id' => 701,
                'source_deck_id' => 301,
                'source_notetype_name' => 'Japanese',
                'source_template_ord' => 0,
            ]);
            // No prior sync assertion anchors this create path; checkpoints are positive auto-increments.
            $syncCheckpointBeforeReview = SyncFeedEntry::query()->max('checkpoint') ?? 0;

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
                'durationMs' => '1250',
                'timeZone' => 'America/New_York',
                'currentOverview' => [
                    'newCount' => 1,
                ],
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.noteId', '501')
                ->assertJsonPath('card.cardType', 'recognition')
                ->assertJsonPath('card.prompt.text', '会社')
                ->assertJsonPath('card.answer.text', 'company')
                ->assertJsonPath('card.state.queueState', 'review')
                ->assertJsonPath('card.state.dueAt', '2026-06-08T15:30:00.000000Z')
                ->assertJsonPath('card.state.introducedAt', '2026-06-05T15:30:00.000000Z')
                ->assertJsonPath('card.state.failedAt', null)
                ->assertJsonPath('card.state.source.noteId', '501')
                ->assertJsonPath('card.state.source.cardId', '701')
                ->assertJsonPath('card.state.source.deckId', '301')
                ->assertJsonPath('card.state.source.deckName', null)
                ->assertJsonPath('card.state.source.notetypeName', 'Japanese')
                ->assertJsonPath('card.state.source.templateOrd', 0)
                ->assertJsonPath('card.answerAudioSource', 'missing')
                ->assertJsonPath('overview.newCount', 0)
                ->assertJsonPath('overview.reviewCount', 1)
                ->assertJsonPath('overview.newCardsPerDay', 20)
                ->assertJsonPath('overview.latestImport.id', self::CONVOLAB_IMPORT_ID)
                ->assertJsonPath('overview.latestImport.status', 'completed')
                ->assertJsonPath('overview.latestImport.sourceType', StudyImportJob::SOURCE_TYPE_ANKI_COLPKG)
                ->assertJsonPath('overview.latestImport.sourceFilename', 'core-2k.apkg');

            $this->assertNotSame(self::CONVOLAB_IMPORT_ID, $importJob->id);

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'), 'review card payload');

            $reviewLogId = $response->json('reviewLogId');

            $this->assertIsString($reviewLogId);
            $this->assertDatabaseHas('card_review_events', [
                'id' => $reviewLogId,
                'card_id' => $card->id,
                'rating' => 'good',
                'reviewed_at' => '2026-06-05 15:30:00',
                'duration_ms' => 1250,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'review',
                'new_queue_position' => null,
                'introduced_at' => '2026-06-05 15:30:00',
                'due_at' => '2026-06-08 15:30:00',
            ]);

            $card->refresh()->load('deck');
            $reviewEvent = CardReviewEvent::query()->findOrFail($reviewLogId);
            $reviewEvent->setRelation('card', $card);

            $this->assertCardReviewEventSyncPayloadRecorded($reviewEvent, SyncFeedOperation::Create);
            $this->assertCardSyncPayloadRecorded(
                $card,
                SyncFeedOperation::Update,
                afterCheckpoint: $syncCheckpointBeforeReview,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_reviews_a_copied_card_by_the_client_id_returned_from_session_start(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-07-16T15:30:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $clientCardId = '98F42A62-8303-410E-AD4D-5A69C55911BB';
            $card = Card::factory()->for($deck)->create([
                'convolab_id' => strtolower($clientCardId),
                'convolab_note_id' => 'c0a8012e-7d2f-4b21-9dd7-14caf2bb1f88',
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
                'prompt_json' => ['type' => 'text', 'text' => '会社'],
                'answer_json' => ['type' => 'text', 'text' => 'company'],
            ]);

            $sessionResponse = $this->postJson('/api/study/session/start');
            $sessionCardId = $sessionResponse->json('data.cards.0.id');

            $this->assertSame(strtolower($clientCardId), $sessionCardId);
            $this->assertNotSame($card->id, $sessionCardId);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => '  '.strtoupper($sessionCardId).'  ',
                'grade' => '  GOOD  ',
                'timeZone' => '  America/New_York  ',
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.id', strtolower($clientCardId))
                ->assertJsonPath('card.state.queueState', 'review');

            $reviewLogId = $response->json('reviewLogId');
            $this->assertIsString($reviewLogId);
            $this->assertDatabaseHas('card_review_events', [
                'id' => $reviewLogId,
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_rejects_malformed_client_card_ids_without_review_side_effects(): void
    {
        $this->signIn();

        foreach (['not-a-card', '98f42a62-8303-410e-ad4d-5a69c55911b', ['invalid']] as $cardId) {
            $this->postJson('/api/study/reviews', [
                'cardId' => $cardId,
                'grade' => CardReviewRating::Good->value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['cardId']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_study_and_canonical_review_creates_share_the_same_rate_limit_bucket(): void
    {
        $limiter = new CardReviewEventCreateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $canonicalCard = $this->cardFor($user);
        $studyCard = $this->cardFor($user);

        $restoreCardReviewEventCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so this matches the request-derived key used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(1)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $canonicalCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ])
                ->assertCreated();

            $this
                ->postJson('/api/study/reviews', [
                    'cardId' => $studyCard->id,
                    'grade' => 'good',
                ])
                ->assertTooManyRequests();

            $this->getJson('/api/study/overview')->assertOk();

            $this->assertSame(1, CardReviewEvent::query()->where('card_id', $canonicalCard->id)->count());
            $this->assertDatabaseMissing('card_review_events', [
                'card_id' => $studyCard->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            $restoreCardReviewEventCreateLimiter();
        }
    }

    public function test_it_normalizes_camel_case_inputs_without_global_trim_middleware(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $this->withoutMiddleware(TrimStrings::class);
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => '  '.strtoupper($card->id).'  ',
                'grade' => '  GOOD  ',
                'timeZone' => '  America/New_York  ',
            ])
                ->assertOk()
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.state.queueState', 'review');

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'), 'normalized review card payload');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_records_native_cards_with_null_note_id_and_nullable_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'source_note_id' => null,
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
                'durationMs' => null,
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.noteId', null)
                ->assertJsonPath('card.state.source.noteId', null);

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'), 'native review card payload');

            $this->assertDatabaseHas('card_review_events', [
                'id' => $response->json('reviewLogId'),
                'duration_ms' => null,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_records_zero_duration_when_provided(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
                'durationMs' => 0,
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('card_review_events', [
                'id' => $response->json('reviewLogId'),
                'duration_ms' => 0,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_treats_omitted_and_null_time_zone_as_default_overview_timezone(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $getStudyOverview = new class extends GetStudyOverviewAction
        {
            /** @var list<string|null> */
            public array $timeZones = [];

            public function __construct() {}

            /**
             * @return array<string, mixed>
             */
            public function handle(
                int $userId,
                ?string $timeZone = null,
                ?Carbon $now = null,
                ?string $deckId = null,
                ?string $courseId = null,
            ): array {
                $this->timeZones[] = $timeZone;

                return [
                    'due_count' => 0,
                    'failed_count' => 0,
                    'new_count' => 0,
                    'new_cards_per_day' => 0,
                    'new_cards_introduced_today' => 0,
                    'new_cards_available_today' => 0,
                    'learning_count' => 0,
                    'review_count' => 0,
                    'suspended_count' => 0,
                    'total_cards' => 0,
                    'latest_import' => null,
                    'next_due_at' => null,
                ];
            }
        };
        $this->app->instance(GetStudyOverviewAction::class, $getStudyOverview);

        $firstResponse = $this->postJson('/api/study/reviews', [
            'cardId' => $firstCard->id,
            'grade' => 'good',
        ]);

        $secondResponse = $this->postJson('/api/study/reviews', [
            'cardId' => $secondCard->id,
            'grade' => 'good',
            'timeZone' => null,
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame([null, null], $getStudyOverview->timeZones);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $firstResponse->json('reviewLogId'),
            'duration_ms' => null,
        ]);
    }

    public function test_review_response_overview_preserves_study_scope_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $course = Course::factory()->for($user)->create();
            $deck = $this->deckFor($user, ['course_id' => $course->id]);
            $scopedCard = Card::factory()->for($deck)->create([
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
            ]);
            $otherCourse = Course::factory()->for($user)->create();
            $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
            Card::factory()->for($otherCourseDeck)->create([
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 2,
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $scopedCard->id,
                'grade' => 'good',
                'timeZone' => 'America/New_York',
                'courseId' => strtoupper($course->id),
                'currentOverview' => [
                    'newCount' => 99,
                ],
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.id', $scopedCard->id)
                ->assertJsonPath('overview.newCount', 0)
                ->assertJsonPath('overview.reviewCount', 1)
                ->assertJsonPath('overview.totalCards', 1)
                ->assertJsonPath('overview.newCardsPerDay', 20);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_validates_camel_case_inputs(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson('/api/study/reviews', [
            'cardId' => 'not-a-ulid',
            'grade' => 'good',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardId']);

        $this->postJson('/api/study/reviews', [
            'cardId' => [strtolower((string) str()->ulid())],
            'grade' => 'good',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardId']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'perfect',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'durationMs' => 86_400_001,
            'timeZone' => 'not-a-zone',
            'courseId' => 'not-a-ulid',
            'deck_id' => ['not-a-ulid'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['durationMs', 'timeZone', 'courseId', 'deck_id']);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'courseId' => strtolower((string) str()->ulid()),
            'course_id' => strtolower((string) str()->ulid()),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);
    }

    public function test_it_returns_not_found_for_missing_or_unowned_cards(): void
    {
        $user = $this->signIn();
        $otherUserCard = $this->cardFor(User::factory()->create(), [
            'convolab_id' => '98f42a62-8303-410e-ad4d-5a69c55911bb',
        ]);
        $deletedOwnedCard = $this->cardFor($user);
        $cardInDeletedOwnedDeck = $this->cardFor($user);
        $deletedOwnedCard->delete();
        $cardInDeletedOwnedDeck->deck()->firstOrFail()->delete();

        $this->postJson('/api/study/reviews', [
            'cardId' => strtolower((string) str()->ulid()),
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $otherUserCard->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => strtoupper((string) $otherUserCard->convolab_id),
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $deletedOwnedCard->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson('/api/study/reviews', [
            'cardId' => $cardInDeletedOwnedDeck->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_it_returns_retryable_response_when_review_event_race_recovery_fails(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->app->instance(ReviewCardAction::class, new class extends ReviewCardAction
        {
            public function __construct() {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::retryableConflict();
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ]);

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Card review event ID conflict could not be resolved; retry the request.')
            ->assertJsonPath('reason', 'card_review_event_retry');
    }

    public function test_it_returns_review_log_id_when_card_disappears_after_review_is_recorded(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $card = Card::factory()->for($deck)->create();
        $otherDeck = $this->deckFor($user);
        Card::factory()->for($otherDeck)->create();

        $realReviewCard = app(ReviewCardAction::class);

        $this->app->instance(ReviewCardAction::class, new class($realReviewCard) extends ReviewCardAction
        {
            public function __construct(private readonly ReviewCardAction $realReviewCard) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                $result = $this->realReviewCard->handle($data);

                Card::query()->whereKey($data->cardId)->firstOrFail()->delete();

                return $result;
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
            'courseId' => strtoupper($course->id),
            'deck_id' => strtoupper($deck->id),
        ]);

        $reviewLogId = $response->json('reviewLogId');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Study card not found after review.')
            ->assertJsonPath('committed', true)
            ->assertJsonPath('cardFetchFailed', true)
            ->assertJsonPath('card', null)
            ->assertJsonPath('overview.newCount', 0)
            ->assertJsonPath('overview.reviewCount', 0)
            ->assertJsonPath('overview.totalCards', 0);

        $this->assertArrayHasKey('card', $response->json());

        $this->assertIsString($reviewLogId);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewLogId,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_returns_conflict_for_owned_review_event_conflicts(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardAction::class, new class($user->id) extends ReviewCardAction
        {
            public function __construct(private readonly int $conflictingUserId) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::conflict($this->conflictingUserId);
            }
        });

        $response = $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');
    }

    public function test_it_hides_cross_user_review_event_conflicts(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $card = $this->cardFor($user);

        $this->app->instance(ReviewCardAction::class, new class($otherUser->id) extends ReviewCardAction
        {
            public function __construct(private readonly int $conflictingUserId) {}

            public function handle(ReviewCardData $data): ReviewCardResult
            {
                throw CardReviewEventConflictException::conflict($this->conflictingUserId);
            }
        });

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => 'good',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');
    }
}
