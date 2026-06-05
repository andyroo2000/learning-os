<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UndoCardReviewEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $reviewEvent = CardReviewEvent::factory()->create();

        $this->deleteJson("/api/card-review-events/{$reviewEvent->id}")
            ->assertUnauthorized();
    }

    public function test_it_undoes_the_latest_review_event_and_returns_the_restored_card(): void
    {
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

        $createResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);
        $reviewEventId = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/card-review-events/{$reviewEventId}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.study_status', 'learning')
            ->assertJsonPath('data.new_queue_position', 4)
            ->assertJsonPath('data.scheduler_state.state', 1)
            ->assertJsonPath('data.due_at', '2026-05-28T09:15:00.000000Z')
            ->assertJsonPath('data.failed_at', '2026-05-24T09:15:00.000000Z')
            ->assertJsonPath('data.last_reviewed_at', '2026-05-25T09:15:00.000000Z');

        $this->assertDatabaseMissing('card_review_events', ['id' => $reviewEventId]);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'learning',
            'new_queue_position' => 4,
            'due_at' => '2026-05-28 09:15:00',
        ]);

        $this->assertDatabaseHas('sync_feed_entries', [
            'resource_type' => 'card_review_event',
            'resource_id' => $reviewEventId,
            'operation' => SyncFeedOperation::Delete->value,
        ]);
        $this->assertSame('learning', SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->latest('checkpoint')
            ->firstOrFail()
            ->payload['study_status']);
    }

    public function test_it_rejects_undoing_a_review_that_is_not_the_latest(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $firstResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);
        $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27T09:20:00Z',
        ])->assertCreated();

        $this->deleteJson('/api/card-review-events/'.$firstResponse->json('data.id'))
            ->assertConflict()
            ->assertJsonPath('reason', 'card_review_event_not_latest');
    }

    public function test_it_hides_another_users_review_event(): void
    {
        $this->signIn();
        $reviewEvent = $this->cardReviewEventFor(User::factory()->create());

        $this->deleteJson("/api/card-review-events/{$reviewEvent->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_hides_review_events_for_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $reviewEvent = $this->cardReviewEventFor($user);
        $reviewEvent->card->delete();

        $this->deleteJson("/api/card-review-events/{$reviewEvent->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }

    public function test_it_returns_unprocessable_when_the_undo_snapshot_is_missing(): void
    {
        $user = $this->signIn();
        $reviewEvent = CardReviewEvent::factory()
            ->for($this->cardFor($user))
            ->create([
                'card_state_before' => null,
            ]);

        $this->deleteJson("/api/card-review-events/{$reviewEvent->id}")
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'card_review_event_missing_undo_state');

        $this->assertDatabaseHas('card_review_events', ['id' => $reviewEvent->id]);
    }
}
