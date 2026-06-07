<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Middleware\TrimStrings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Flashcards\Concerns\UsesStudyCardRateLimitOverrides;
use Tests\TestCase;

class PerformCardStudyActionApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyCardRateLimitOverrides;

    public function test_actions_require_authentication(): void
    {
        $card = Card::factory()->create();

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'now',
        ])->assertUnauthorized();
    }

    public function test_it_sets_a_custom_due_date_and_returns_card_plus_overview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $card = $this->cardFor($user, [
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
            ]);

            $response = $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => '2026-06-05T14:15:00Z',
                'time_zone' => 'America/New_York',
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('data.card.id', $card->id)
                ->assertJsonPath('data.card.study_status', 'review')
                ->assertJsonPath('data.card.new_queue_position', null)
                ->assertJsonPath('data.card.due_at', '2026-06-05T14:15:00.000000Z')
                ->assertJsonPath('data.overview.review_count', 1)
                ->assertJsonPath('data.overview.new_count', 0)
                ->assertJsonMissingPath('data.overview.failed_due_count');

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'review',
                'new_queue_position' => null,
                'due_at' => '2026-06-05 14:15:00',
            ]);
            $this->assertSame($card->id, SyncFeedEntry::query()->sole()->resource_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_actions_are_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
        ]);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser, [
            'study_status' => CardStudyStatus::Review,
        ]);

        $this->withStudyCardRateLimitOverride(
            StudyCardActionRateLimiter::NAME,
            [$user->id, $otherUser->id],
            function () use ($card, $otherCard, $otherUser, $user): void {
                foreach (['2026-06-06T14:15:00Z', '2026-06-07T14:15:00Z'] as $dueAt) {
                    $this
                        ->postJson("/api/cards/{$card->id}/actions", $this->setDuePayload($dueAt))
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson("/api/cards/{$otherCard->id}/actions", $this->setDuePayload('2026-06-06T09:00:00Z'))
                    ->assertOk();

                $this->signIn($user);

                $this
                    ->postJson("/api/cards/{$card->id}/actions", $this->setDuePayload('2026-06-08T14:15:00Z'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$card->id}")
                    ->assertOk()
                    ->assertJsonPath('data.due_at', '2026-06-07T14:15:00.000000Z');

                $this->assertSame('2026-06-07T14:15:00.000000Z', $card->refresh()->due_at?->toJSON());
                $this->assertSame('2026-06-06T09:00:00.000000Z', $otherCard->refresh()->due_at?->toJSON());
                $this->assertDatabaseMissing('sync_feed_entries', [
                    'user_id' => $user->id,
                    'payload->due_at' => '2026-06-08T14:15:00.000000Z',
                ]);
            },
        );
    }

    public function test_it_returns_a_deck_scoped_overview_when_deck_id_is_provided(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $card = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);
        Card::factory()->for($otherDeck)->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'deck_id' => $deck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.card.study_status', 'suspended')
            ->assertJsonPath('data.overview.review_count', 0)
            ->assertJsonPath('data.overview.suspended_count', 1)
            ->assertJsonPath('data.overview.total_cards', 1)
            ->assertJsonMissingPath('data.overview.failed_due_count');
    }

    public function test_it_normalizes_action_and_mode_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => '  SET_DUE  ',
                'mode' => '  NOW  ',
            ])
                ->assertOk()
                ->assertJsonPath('data.card.due_at', '2026-06-04T12:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_normalizes_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $card = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);
        Card::factory()->for($otherDeck)->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => '  SUSPEND  ',
            'deck_id' => '  '.strtoupper($deck->id).'  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.review_count', 0)
            ->assertJsonPath('data.overview.suspended_count', 1)
            ->assertJsonPath('data.overview.total_cards', 1);
    }

    public function test_it_suspends_a_card_without_requiring_set_due_mode(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'time_zone' => 'America/New_York',
        ])
            ->assertOk()
            ->assertJsonPath('data.card.study_status', 'suspended')
            ->assertJsonPath('data.card.due_at', '2026-06-05T12:00:00.000000Z')
            ->assertJsonPath('data.overview.review_count', 0)
            ->assertJsonPath('data.overview.suspended_count', 1);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'suspended',
        ]);
        $this->assertSame('suspended', SyncFeedEntry::query()->sole()->payload['study_status']);
    }

    public function test_it_forgets_a_card_and_returns_new_card_overview_counts(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'forget',
        ])
            ->assertOk()
            ->assertJsonPath('data.card.study_status', 'new')
            ->assertJsonPath('data.card.new_queue_position', 1)
            ->assertJsonPath('data.card.due_at', null)
            ->assertJsonPath('data.card.introduced_at', null)
            ->assertJsonPath('data.card.failed_at', null)
            ->assertJsonPath('data.card.last_reviewed_at', null)
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.overview.learning_count', 0);

        $this->assertSame('new', SyncFeedEntry::query()->sole()->payload['study_status']);
    }

    public function test_it_unsuspends_a_card_and_preserves_existing_due_date(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => '2026-06-05T14:15:00Z',
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'unsuspend',
        ])
            ->assertOk()
            ->assertJsonPath('data.card.study_status', 'review')
            ->assertJsonPath('data.card.due_at', '2026-06-05T14:15:00.000000Z')
            ->assertJsonPath('data.overview.review_count', 1)
            ->assertJsonPath('data.overview.suspended_count', 0);
    }

    public function test_it_unsuspends_a_card_to_the_scheduler_state_status(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => '2026-06-05T14:15:00Z',
            'scheduler_state' => [
                'due' => '2026-06-05T14:15:00.000000Z',
                'stability' => 10,
                'difficulty' => 4,
                'elapsed_days' => 2,
                'scheduled_days' => 5,
                'learning_steps' => 0,
                'reps' => 3,
                'lapses' => 1,
                'state' => 1,
                'last_review' => '2026-06-01T09:15:00.000000Z',
            ],
        ]);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'unsuspend',
        ])
            ->assertOk()
            ->assertJsonPath('data.card.study_status', 'learning')
            ->assertJsonPath('data.card.due_at', '2026-06-05T14:15:00.000000Z')
            ->assertJsonPath('data.overview.learning_count', 1)
            ->assertJsonPath('data.overview.suspended_count', 0);
    }

    public function test_action_must_be_a_supported_string(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => ['suspend'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'not-real',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    public function test_tomorrow_mode_requires_a_valid_timezone(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'tomorrow',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'tomorrow',
            'time_zone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_custom_date_requires_a_strict_iso_datetime_within_ten_years(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $card = $this->cardFor($this->signIn());

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => 'tomorrow',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => ['2026-06-05T14:15:00Z'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => '2037-06-04T12:00:00Z',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_hides_another_users_card_before_validating_payload(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
        ]);

        $this->postJson("/api/cards/{$otherCard->id}/actions", [
            'action' => 'not-real',
            'mode' => ['tomorrow'],
        ])->assertNotFound();
    }

    public function test_deck_id_must_match_the_action_card_deck(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $otherDeck = $this->deckFor($user);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'deck_id' => $otherDeck->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_deck_id_must_be_a_valid_ulid_when_provided(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'deck_id' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'deck_id' => ['01J00000000000000000000000'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_deck_id_must_not_be_blank_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $card = $this->cardFor($this->signIn());

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'suspend',
            'deck_id' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    /**
     * @return array{action: string, mode: string, due_at: string}
     */
    private function setDuePayload(string $dueAt): array
    {
        return [
            'action' => 'set_due',
            'mode' => 'custom_date',
            'due_at' => $dueAt,
        ];
    }
}
