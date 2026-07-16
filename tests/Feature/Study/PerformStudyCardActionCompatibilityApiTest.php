<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
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
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class PerformStudyCardActionCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $this->postJson("/api/study/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'now',
        ])->assertUnauthorized();
    }

    public function test_it_performs_an_action_with_the_card_identifier_returned_by_copied_note_detail(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'study_status' => CardStudyStatus::Review,
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->convolab_note_id = '9e33f12d-cf38-409b-bbf1-6fddd9977576';
        $card->save();

        $browserCardId = $this->getJson('/api/study/browser/9e33f12d-cf38-409b-bbf1-6fddd9977576')
            ->assertOk()
            ->assertJsonPath('cards.0.id', $card->convolab_id)
            ->json('cards.0.id');

        $this->postJson("/api/study/cards/{$browserCardId}/actions", [
            'action' => 'suspend',
        ])
            ->assertOk()
            ->assertJsonPath('card.id', $card->convolab_id)
            ->assertJsonPath('card.noteId', $card->convolab_note_id)
            ->assertJsonPath('card.state.source.noteId', $card->convolab_note_id)
            ->assertJsonPath('card.state.queueState', 'suspended');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => CardStudyStatus::Suspended->value,
        ]);
    }

    public function test_it_sets_a_custom_due_date_with_a_convolab_compatible_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
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

            $response = $this->postJson('/api/study/cards/'.strtoupper($card->id).'/actions', [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => '2026-06-06T14:15:00Z',
                'timeZone' => 'America/New_York',
                'currentOverview' => [
                    'newCount' => 99,
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
                ->assertJsonPath('card.state.dueAt', '2026-06-06T14:15:00.000000Z')
                ->assertJsonPath('card.state.source.noteId', '501')
                ->assertJsonPath('card.state.source.cardId', '701')
                ->assertJsonPath('card.state.source.deckId', '301')
                ->assertJsonPath('card.answerAudioSource', 'missing')
                ->assertJsonPath('overview.newCount', 0)
                ->assertJsonPath('overview.reviewCount', 1)
                ->assertJsonPath('overview.newCardsPerDay', 20);

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'review',
                'new_queue_position' => null,
                'due_at' => '2026-06-06 14:15:00',
            ]);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'card',
                'resource_id' => $card->id,
                'operation' => SyncFeedOperation::Update->value,
            ]);
            $this->assertSame('review', SyncFeedEntry::query()->sole()->payload['study_status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_custom_due_date_with_explicit_offset_is_stored_as_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => '2026-06-06T10:15:00-04:00',
            ])
                ->assertOk()
                ->assertJsonPath('card.state.dueAt', '2026-06-06T14:15:00.000000Z');

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'due_at' => '2026-06-06 14:15:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_normalizes_action_mode_due_at_and_timezone_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => '  SET_DUE  ',
                'mode' => '  CUSTOM_DATE  ',
                'dueAt' => '  2026-06-07T09:00:00Z  ',
                'timeZone' => '  America/New_York  ',
            ])
                ->assertOk()
                ->assertJsonPath('card.state.queueState', 'review')
                ->assertJsonPath('card.state.dueAt', '2026-06-07T09:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_performs_non_set_due_actions_without_requiring_mode(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T14:15:00Z',
        ]);

        $this->postJson("/api/study/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'mode' => 'custom_date',
            'timeZone' => 'America/New_York',
        ])
            ->assertOk()
            ->assertJsonPath('card.state.queueState', 'suspended')
            ->assertJsonPath('card.state.dueAt', '2026-06-05T14:15:00.000000Z')
            ->assertJsonPath('overview.reviewCount', 0)
            ->assertJsonPath('overview.suspendedCount', 1);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'suspended',
        ]);
    }

    public function test_it_forgets_a_card_and_returns_new_card_counts(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $this->postJson("/api/study/cards/{$card->id}/actions", [
            'action' => 'forget',
        ])
            ->assertOk()
            ->assertJsonPath('card.state.queueState', 'new')
            ->assertJsonPath('card.state.dueAt', null)
            ->assertJsonPath('card.state.introducedAt', null)
            ->assertJsonPath('card.state.failedAt', null)
            ->assertJsonPath('overview.newCount', 1)
            ->assertJsonPath('overview.learningCount', 0);

        $this->assertSame('new', SyncFeedEntry::query()->sole()->payload['study_status']);
    }

    public function test_it_sets_due_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
            ]);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'now',
            ])
                ->assertOk()
                ->assertJsonPath('card.state.queueState', 'review')
                ->assertJsonPath('card.state.dueAt', '2026-06-05T15:30:00.000000Z')
                ->assertJsonPath('overview.reviewCount', 1)
                ->assertJsonPath('overview.newCount', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_action_response_overview_preserves_study_scope_filters(): void
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
            $otherDeck = $this->deckFor($user);
            Card::factory()->for($otherDeck)->create([
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 2,
            ]);

            $this->postJson("/api/study/cards/{$scopedCard->id}/actions", [
                'action' => 'set_due',
                'mode' => 'now',
                'course_id' => strtoupper($course->id),
                'deckId' => strtoupper($deck->id),
                'currentOverview' => [
                    'newCount' => 99,
                ],
            ])
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

    public function test_it_sets_due_tomorrow_in_the_requested_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T23:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'tomorrow',
                'timeZone' => 'America/New_York',
            ])
                ->assertOk()
                ->assertJsonPath('card.state.queueState', 'review')
                ->assertJsonPath('card.state.dueAt', '2026-06-06T13:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_unsuspends_a_card_and_preserves_existing_due_date(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => '2026-06-05T14:15:00Z',
        ]);

        $this->postJson("/api/study/cards/{$card->id}/actions", [
            'action' => 'unsuspend',
        ])
            ->assertOk()
            ->assertJsonPath('card.state.queueState', 'review')
            ->assertJsonPath('card.state.dueAt', '2026-06-05T14:15:00.000000Z')
            ->assertJsonPath('overview.reviewCount', 1)
            ->assertJsonPath('overview.suspendedCount', 0);

        $this->assertSame('review', SyncFeedEntry::query()->sole()->payload['study_status']);
    }

    /**
     * CI runs the suite serially today; keep this marker if parallel workers start honoring group exclusions.
     */
    #[Group('no-parallel')]
    public function test_it_rate_limits_study_card_actions_by_user(): void
    {
        $limiter = new StudyCardActionRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);

        $restoreStudyCardActionLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardActionRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // RateLimiter definitions are process-global; keep this sequential test out of parallel workers.
            RateLimiter::for(StudyCardActionRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            foreach (['2026-06-06T14:15:00Z', '2026-06-07T14:15:00Z'] as $dueAt) {
                $this
                    ->postJson("/api/study/cards/{$card->id}/actions", [
                        'action' => 'set_due',
                        'mode' => 'custom_date',
                        'dueAt' => $dueAt,
                    ])
                    ->assertOk();
            }

            $this->signIn($otherUser);

            $this
                ->postJson("/api/study/cards/{$otherCard->id}/actions", [
                    'action' => 'set_due',
                    'mode' => 'custom_date',
                    'dueAt' => '2026-06-06T09:00:00Z',
                ])
                ->assertOk();

            $this->signIn($user);

            $this
                ->postJson("/api/study/cards/{$card->id}/actions", [
                    'action' => 'set_due',
                    'mode' => 'custom_date',
                    'dueAt' => '2026-06-08T14:15:00Z',
                ])
                ->assertTooManyRequests();

            $this->assertSame('2026-06-07T14:15:00.000000Z', $card->refresh()->due_at?->toJSON());
            $this->assertSame('2026-06-06T09:00:00.000000Z', $otherCard->refresh()->due_at?->toJSON());
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, SyncFeedEntry::query()->where('user_id', $otherUser->id)->count());
            $this->assertDatabaseMissing('sync_feed_entries', [
                'user_id' => $user->id,
                'payload->due_at' => '2026-06-08T14:15:00.000000Z',
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardActionLimiter();
        }
    }

    public function test_it_returns_not_found_for_missing_unowned_or_deleted_cards_before_payload_validation(): void
    {
        $user = $this->signIn();
        $otherUserCard = $this->cardFor(User::factory()->create());
        $deletedOwnedCard = $this->cardFor($user);
        $cardInDeletedOwnedDeck = $this->cardFor($user);
        $deletedOwnedCard->delete();
        $cardInDeletedOwnedDeck->deck()->firstOrFail()->delete();

        $this->postJson('/api/study/cards/'.strtolower((string) str()->ulid()).'/actions', [
            'action' => 'not-real',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson("/api/study/cards/{$otherUserCard->id}/actions", [
            'action' => 'not-real',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson("/api/study/cards/{$deletedOwnedCard->id}/actions", [
            'action' => 'not-real',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->postJson("/api/study/cards/{$cardInDeletedOwnedDeck->id}/actions", [
            'action' => 'not-real',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Study card not found.');

        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_validates_camel_case_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn());

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => ['suspend'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['action']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'not-real',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['action']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'tomorrow',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['timeZone']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'tomorrow',
                'timeZone' => ['America/New_York'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['timeZone']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => ['2026-06-05T15:30:00Z'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['dueAt']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => 'tomorrow',
                'currentOverview' => 'stale',
                'course_id' => 'not-a-ulid',
                'deckId' => ['not-a-ulid'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['dueAt', 'currentOverview', 'course_id', 'deckId']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'suspend',
                'courseId' => strtolower((string) str()->ulid()),
                'course_id' => strtolower((string) str()->ulid()),
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['courseId']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => 1780668900,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['dueAt']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => '2026-06-05T15:30:00',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['dueAt']);

            $this->postJson("/api/study/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'dueAt' => '2037-06-05T15:30:00Z',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['dueAt']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
