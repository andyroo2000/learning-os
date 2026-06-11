<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\PerformCardStudyAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class PerformCardStudyActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_suspend_sets_status_and_records_a_sync_entry(): void
    {
        $user = $this->signIn();
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $dueAt,
            'introduced_at' => '2026-06-01T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $result = app(PerformCardStudyAction::class)->handle($card, 'suspend');

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Suspended, $result->card->study_status);
        $this->assertSame($dueAt->toJSON(), $result->card->due_at?->toJSON());

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card, SyncFeedOperation::Update);

        $this->assertSame('suspended', $entry->payload['study_status']);
        $this->assertSame($dueAt->toJSON(), $entry->payload['due_at']);
    }

    public function test_forget_resets_the_study_schedule_to_new(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $result = app(PerformCardStudyAction::class)->handle($card, 'forget');

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::New, $result->card->study_status);
        $this->assertSame(1, $result->card->new_queue_position);
        $this->assertNull($result->card->due_at);
        $this->assertNull($result->card->introduced_at);
        $this->assertNull($result->card->failed_at);
        $this->assertNull($result->card->last_reviewed_at);
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card, SyncFeedOperation::Update);

        $this->assertSame('new', $entry->payload['study_status']);
    }

    public function test_unsuspend_restores_review_status_and_preserves_existing_due_date(): void
    {
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => $dueAt,
        ]);

        $result = app(PerformCardStudyAction::class)->handle(
            card: $card,
            action: 'unsuspend',
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Review, $result->card->study_status);
        $this->assertSame($dueAt->toJSON(), $result->card->due_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card, SyncFeedOperation::Update);

        $this->assertSame('review', $entry->payload['study_status']);
    }

    public function test_unsuspend_restores_scheduler_state_status_and_preserves_existing_due_date(): void
    {
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => $dueAt,
            'scheduler_state' => [
                'due' => '2026-06-05T14:15:00.000000Z',
                'stability' => 10,
                'difficulty' => 4,
                'elapsed_days' => 2,
                'scheduled_days' => 5,
                'learning_steps' => 0,
                'reps' => 3,
                'lapses' => 1,
                'state' => 3,
                'last_review' => '2026-06-01T09:15:00.000000Z',
            ],
        ]);

        $result = app(PerformCardStudyAction::class)->handle(
            card: $card,
            action: 'unsuspend',
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Relearning, $result->card->study_status);
        $this->assertSame($dueAt->toJSON(), $result->card->due_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card, SyncFeedOperation::Update);

        $this->assertSame('relearning', $entry->payload['study_status']);
    }

    public function test_unsuspend_sets_due_now_when_no_due_date_exists(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => null,
        ]);

        $result = app(PerformCardStudyAction::class)->handle(
            card: $card,
            action: 'unsuspend',
            now: $now,
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Review, $result->card->study_status);
        $this->assertSame($now->toJSON(), $result->card->due_at?->toJSON());
    }

    public function test_it_normalizes_actions_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Review,
        ]);

        $result = app(PerformCardStudyAction::class)->handle($card, '  SUSPEND  ');

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Suspended, $result->card->study_status);
    }

    public function test_it_rejects_invalid_actions_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card action must be one of: set_due, suspend, unsuspend, forget.');

        app(PerformCardStudyAction::class)->handle(
            card: $this->cardFor($this->signIn()),
            action: 'bury',
        );
    }
}
