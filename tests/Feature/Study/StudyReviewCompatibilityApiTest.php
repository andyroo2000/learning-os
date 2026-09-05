<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    public function test_it_rejects_reviews_for_progression_locked_cards(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->postJson('/api/study/reviews', [
            'cardId' => $card->id,
            'grade' => CardReviewRating::Good->value,
        ])
            ->assertConflict()
            ->assertJsonPath('reason', 'card_progression_locked');

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
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

            $this->assertCompatibleReviewResponse($response, $card);

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
                'study_status' => 'learning',
                'new_queue_position' => null,
                'introduced_at' => '2026-06-05 15:30:00',
                'due_at' => '2026-06-05 15:40:00',
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

    public function test_same_timestamp_resubmissions_without_an_identity_key_are_rejected_before_double_applying(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            $card = $this->cardFor($user);

            $firstResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
            ]);
            $secondResponse = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'easy',
            ]);

            $firstResponse->assertOk();
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
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_replays_a_client_identified_review_without_advancing_fsrs_or_sync_twice(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);
        $clientReviewId = strtolower((string) Str::ulid());
        $payload = [
            'cardId' => $card->id,
            'grade' => 'good',
            'durationMs' => 1250,
            'clientReviewId' => '  '.strtoupper($clientReviewId).'  ',
            'reviewedAt' => '  2026-06-05T15:30:00.123999Z  ',
            'timeZone' => 'America/New_York',
            'currentOverview' => ['newCount' => 99],
        ];

        $firstResponse = $this->postJson('/api/study/reviews', $payload);
        $cardAfterFirstReview = $card->refresh();
        $syncEntriesAfterFirstReview = $this->syncEntriesFor($user);

        $secondResponse = $this->postJson('/api/study/reviews', [
            ...$payload,
            'reviewedAt' => '2026-06-05T15:30:00.123111Z',
        ]);
        $contextChangedResponse = $this->postJson('/api/study/reviews', [
            ...$payload,
            'reviewedAt' => '2026-06-05T15:30:00.123555Z',
            // These response-context fields are intentionally outside the logical review identity.
            'timeZone' => 'Asia/Tokyo',
            'currentOverview' => ['newCount' => 0],
            'courseId' => strtolower((string) Str::ulid()),
            'deckId' => strtolower((string) Str::ulid()),
        ]);

        $firstResponse
            ->assertOk()
            ->assertJsonPath('reviewLogId', $clientReviewId);
        $secondResponse
            ->assertOk()
            ->assertJsonPath('reviewLogId', $clientReviewId)
            ->assertJsonPath('card.state.queueState', 'review');
        $contextChangedResponse
            ->assertOk()
            ->assertJsonPath('reviewLogId', $clientReviewId);
        $this->assertSame($firstResponse->json(), $secondResponse->json());

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $clientReviewId,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-06-05 15:30:00.123',
            'duration_ms' => 1250,
        ]);
        $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
        $this->assertSame(
            '2026-06-05T15:30:00.123000Z',
            CardReviewEvent::query()->findOrFail($clientReviewId)->reviewed_at->toJSON(),
        );
        $this->assertSame($cardAfterFirstReview->scheduler_state, $card->scheduler_state);
        $this->assertSame($cardAfterFirstReview->due_at->toJSON(), $card->due_at->toJSON());
        $this->assertSame($syncEntriesAfterFirstReview, $this->syncEntriesFor($user));
    }

    public function test_it_rejects_reusing_a_client_review_id_for_different_review_metadata_without_side_effects(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $clientReviewId = strtolower((string) Str::ulid());
        $payload = [
            'cardId' => $card->id,
            'grade' => 'good',
            'durationMs' => 1250,
            'clientReviewId' => $clientReviewId,
            'reviewedAt' => '2026-06-05T15:30:00Z',
        ];

        $this->postJson('/api/study/reviews', $payload)->assertOk();
        $cardAfterFirstReview = $card->refresh();
        $syncEntryCountAfterFirstReview = SyncFeedEntry::query()->where('user_id', $user->id)->count();

        $this->postJson('/api/study/reviews', [
            ...$payload,
            'grade' => 'easy',
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Card review event ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_review_event_id_conflict');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertSame($cardAfterFirstReview->scheduler_state, $card->refresh()->scheduler_state);
        $this->assertSame(
            $syncEntryCountAfterFirstReview,
            SyncFeedEntry::query()->where('user_id', $user->id)->count(),
        );
    }

    public function test_it_hides_replays_whose_client_review_id_belongs_to_another_user(): void
    {
        $firstUser = $this->signIn();
        $firstCard = $this->cardFor($firstUser);
        $clientReviewId = strtolower((string) Str::ulid());
        $payload = [
            'cardId' => $firstCard->id,
            'grade' => 'good',
            'clientReviewId' => $clientReviewId,
            'reviewedAt' => '2026-06-05T15:30:00Z',
        ];

        $this->postJson('/api/study/reviews', $payload)->assertOk();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);
        $this->actingAs($otherUser);

        $this->postJson('/api/study/reviews', [
            ...$payload,
            'cardId' => $otherCard->id,
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');

        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertSame(1, $firstCard->refresh()->scheduler_state['reps']);
        $this->assertNull($otherCard->refresh()->scheduler_state);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    private function assertCompatibleReviewResponse(TestResponse $response, Card $card): void
    {
        $response
            ->assertOk()
            ->assertJsonPath('card.id', $card->id)
            ->assertJsonPath('card.noteId', '501')
            ->assertJsonPath('card.cardType', 'recognition')
            ->assertJsonPath('card.prompt.text', '会社')
            ->assertJsonPath('card.answer.text', 'company')
            ->assertJsonPath('card.state.queueState', 'learning')
            ->assertJsonPath('card.state.dueAt', '2026-06-05T15:40:00.000Z')
            ->assertJsonPath('card.state.introducedAt', '2026-06-05T15:30:00.000Z')
            ->assertJsonPath('card.state.failedAt', null)
            ->assertJsonPath('card.state.source.noteId', '501')
            ->assertJsonPath('card.state.source.cardId', '701')
            ->assertJsonPath('card.state.source.deckId', '301')
            ->assertJsonPath('card.state.source.deckName', null)
            ->assertJsonPath('card.state.source.notetypeName', 'Japanese')
            ->assertJsonPath('card.state.source.templateOrd', 0)
            ->assertJsonPath('card.answerAudioSource', 'missing')
            ->assertJsonPath('overview.newCount', 0)
            ->assertJsonPath('overview.learningCount', 1)
            ->assertJsonPath('overview.reviewCount', 0)
            ->assertJsonPath('overview.newCardsPerDay', 20)
            ->assertJsonPath('overview.latestImport.id', self::CONVOLAB_IMPORT_ID)
            ->assertJsonPath('overview.latestImport.status', 'completed')
            ->assertJsonPath('overview.latestImport.sourceType', StudyImportJob::SOURCE_TYPE_ANKI_COLPKG)
            ->assertJsonPath('overview.latestImport.sourceFilename', 'core-2k.apkg');
    }

    /** @return array<int, array<string, mixed>> */
    private function syncEntriesFor(User $user): array
    {
        return SyncFeedEntry::query()
            ->where('user_id', $user->id)
            ->orderBy('checkpoint')
            ->get()
            ->map->only(['domain', 'resource_type', 'resource_id', 'operation', 'payload'])
            ->all();
    }
}
