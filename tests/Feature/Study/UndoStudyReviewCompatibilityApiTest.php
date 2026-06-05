<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UndoStudyReviewCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create();

        $this->deleteJson("/api/study/reviews/{$reviewEvent->id}")
            ->assertUnauthorized();
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
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'card_review_event',
                'resource_id' => $reviewLogId,
                'operation' => SyncFeedOperation::Delete->value,
            ]);
            $this->assertSame('learning', SyncFeedEntry::query()
                ->where('resource_type', 'card')
                ->latest('checkpoint')
                ->firstOrFail()
                ->payload['study_status']);
        } finally {
            Carbon::setTestNow();
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
}
