<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudyReviewCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

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
                ->assertJsonPath('overview.latestImport.id', $importJob->id)
                ->assertJsonPath('overview.latestImport.source_filename', 'core-2k.apkg');

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
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'card_review_event',
                'resource_id' => $reviewLogId,
                'operation' => SyncFeedOperation::Create->value,
            ]);
            $this->assertSame('review', SyncFeedEntry::query()
                ->where('resource_type', 'card')
                ->latest('checkpoint')
                ->firstOrFail()
                ->payload['study_status']);
        } finally {
            Carbon::setTestNow();
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

            $this->postJson('/api/study/reviews', [
                'cardId' => '  '.strtoupper($card->id).'  ',
                'grade' => '  GOOD  ',
                'timeZone' => '  America/New_York  ',
            ])
                ->assertOk()
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.state.queueState', 'review');
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
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['durationMs', 'timeZone']);
    }

    public function test_it_returns_not_found_for_missing_or_unowned_cards(): void
    {
        $user = $this->signIn();
        $otherUserCard = $this->cardFor(User::factory()->create());
        $deletedOwnedCard = $this->cardFor($user);
        $deletedOwnedCard->delete();

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
            'cardId' => $deletedOwnedCard->id,
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
