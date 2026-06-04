<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\UpdateCardStudyStatusAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class UpdateCardStudyStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_card_study_status_and_records_a_sync_entry(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');
        $card = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => $dueAt,
            'introduced_at' => '2026-06-01T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $result = app(UpdateCardStudyStatusAction::class)->handle($card, CardStudyStatus::Suspended);
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Suspended, $updatedCard->study_status);
        $this->assertSame($dueAt->toJSON(), $updatedCard->due_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('card', $entry->resource_type);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame([
            'id' => $card->id,
            'deck_id' => $deck->id,
            'course_id' => $course->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'study_status' => 'suspended',
            'new_queue_position' => null,
            'due_at' => $dueAt->toJSON(),
            'introduced_at' => $updatedCard->introduced_at?->toJSON(),
            'failed_at' => null,
            'last_reviewed_at' => $updatedCard->last_reviewed_at?->toJSON(),
            'created_at' => $updatedCard->created_at?->toJSON(),
            'updated_at' => $updatedCard->updated_at?->toJSON(),
            'deleted_at' => null,
        ], $entry->payload);
    }

    public function test_it_normalizes_string_statuses_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $result = app(UpdateCardStudyStatusAction::class)->handle($card, '  BURIED  ');

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Buried, $result->card->study_status);
    }

    public function test_new_status_resets_study_schedule(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $result = app(UpdateCardStudyStatusAction::class)->handle($card, 'new');

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::New, $result->card->study_status);
        $this->assertSame(1, $result->card->new_queue_position);
        $this->assertNull($result->card->due_at);
        $this->assertNull($result->card->introduced_at);
        $this->assertNull($result->card->failed_at);
        $this->assertNull($result->card->last_reviewed_at);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'new_queue_position' => 1,
        ]);
    }

    public function test_non_new_status_clears_new_queue_position(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => 7,
        ]);

        $result = app(UpdateCardStudyStatusAction::class)->handle($card, CardStudyStatus::Suspended);

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Suspended, $result->card->study_status);
        $this->assertNull($result->card->new_queue_position);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'suspended',
            'new_queue_position' => null,
        ]);
        $this->assertNull(SyncFeedEntry::query()->sole()->payload['new_queue_position']);
    }

    public function test_it_is_idempotent_when_status_is_unchanged(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $result = app(UpdateCardStudyStatusAction::class)->handle($card, 'suspended');

        $card->refresh();

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($timestamp->toJSON(), $card->updated_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_blank_statuses_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status must not be blank.');

        app(UpdateCardStudyStatusAction::class)->handle($card, '   ');
    }

    public function test_it_rejects_malformed_statuses_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card study_status must be one of: new, learning, review, relearning, suspended, buried.');

        app(UpdateCardStudyStatusAction::class)->handle($card, 'queued');
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Review,
        ]);
        $updateCardStudyStatus = new UpdateCardStudyStatusAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $updateCardStudyStatus->handle($card, CardStudyStatus::Suspended);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'review',
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
