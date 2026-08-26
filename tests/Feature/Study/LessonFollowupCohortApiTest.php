<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class LessonFollowupCohortApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_groups_owned_new_cards_idempotently_and_studies_that_cohort_now(): void
    {
        Carbon::setTestNow('2026-08-26T18:30:00Z');
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 0,
            'lesson_batch_size' => 3,
        ]);
        $unrelated = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $cards = collect(range(2, 5))->map(fn (int $position) => $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => $position,
        ]));
        $cohortId = strtolower((string) Str::ulid());
        $payload = [
            'cohortId' => strtoupper($cohortId),
            'cardIds' => $cards->pluck('id')->map('strtoupper')->reverse()->values()->all(),
            'label' => 'iTalki · August 26',
        ];

        $this->postJson('/api/study/introduction-cohorts/lesson-followup', $payload)
            ->assertOk()
            ->assertJsonMissingPath('data')
            ->assertJsonPath('id', $cohortId)
            ->assertJsonPath('sourceKind', CardSourceKind::LessonFollowup->value)
            ->assertJsonPath('label', 'iTalki · August 26')
            ->assertJsonPath('priorityUntil', '2026-09-02T18:30:00.000Z')
            ->assertJsonCount(4, 'cards');

        foreach ($cards as $card) {
            $card->refresh();
            $this->assertSame(CardSourceKind::LessonFollowup->value, $card->source_kind);
            $this->assertSame($cohortId, $card->introduction_cohort_id);
            $this->assertSame(CardSelectionPolicy::ReviewSoon, $card->selection_policy);
            $this->assertSame('2026-09-02T18:30:00.000000Z', $card->priority_until?->toJSON());
        }
        $this->assertSame(4, SyncFeedEntry::query()->where('resource_type', 'card')->count());

        $this->postJson('/api/study/introduction-cohorts/lesson-followup', $payload)
            ->assertOk()
            ->assertJsonPath('id', $cohortId);
        $this->assertSame(4, SyncFeedEntry::query()->where('resource_type', 'card')->count());

        $this->postJson("/api/study/introduction-cohorts/{$cohortId}/lessons/start", [
            'time_zone' => 'America/New_York',
        ])
            ->assertOk()
            ->assertJsonPath('overview.newCardsAvailableToday', 0)
            ->assertJsonPath('cards.0.id', $cards[0]->id)
            ->assertJsonPath('cards.1.id', $cards[1]->id)
            ->assertJsonPath('cards.2.id', $cards[2]->id)
            ->assertJsonMissing(['id' => $cards[3]->id])
            ->assertJsonMissing(['id' => $unrelated->id])
            ->assertJsonCount(3, 'cards');
    }

    public function test_it_rejects_unavailable_cards_without_partial_writes_and_conflicting_replays(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $cohortId = strtolower((string) Str::ulid());

        $this->postJson('/api/study/introduction-cohorts/lesson-followup', [
            'cohortId' => $cohortId,
            'cardIds' => [$newCard->id, $reviewCard->id],
        ])->assertConflict();

        $this->assertDatabaseMissing('card_introduction_cohorts', ['id' => $cohortId]);
        $this->assertNull($newCard->refresh()->introduction_cohort_id);
        $this->assertSame(0, SyncFeedEntry::query()->count());

        $this->postJson('/api/study/introduction-cohorts/lesson-followup', [
            'cohortId' => $cohortId,
            'cardIds' => [$newCard->id],
        ])->assertOk();
        $this->postJson('/api/study/introduction-cohorts/lesson-followup', [
            'cohortId' => $cohortId,
            'cardIds' => [$newCard->id],
            'label' => 'Different retry',
        ])->assertConflict();

        $this->assertSame(1, CardIntroductionCohort::query()->count());
    }

    public function test_study_now_hides_other_users_and_non_lesson_cohorts(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $otherCohort = new CardIntroductionCohort;
        $otherCohort->user_id = $otherUser->id;
        $otherCohort->source_kind = CardSourceKind::LessonFollowup;
        $otherCohort->saveOrFail();
        $waniKaniCohort = new CardIntroductionCohort;
        $waniKaniCohort->user_id = $user->id;
        $waniKaniCohort->source_kind = CardSourceKind::WaniKani;
        $waniKaniCohort->saveOrFail();

        $this->postJson("/api/study/introduction-cohorts/{$otherCohort->id}/lessons/start")
            ->assertNotFound();
        $this->postJson("/api/study/introduction-cohorts/{$waniKaniCohort->id}/lessons/start")
            ->assertNotFound();
    }

    public function test_create_requires_authentication_and_validates_bounded_distinct_ids(): void
    {
        $this->postJson('/api/study/introduction-cohorts/lesson-followup')->assertUnauthorized();

        $this->signIn();
        $id = (string) Str::ulid();
        $this->postJson('/api/study/introduction-cohorts/lesson-followup', [
            'cohortId' => $id,
            'cardIds' => [$id, $id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['cardIds.1']);
    }
}
