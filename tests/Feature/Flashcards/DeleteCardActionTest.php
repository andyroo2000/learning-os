<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DeleteCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_card(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();

        $result = app(DeleteCardAction::class)->handle($card);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($card, $result->card);
        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($card->deck->user_id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('card', $entry->resource_type);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame([
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'course_id' => $course->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'card_type' => 'recognition',
            'prompt_json' => null,
            'answer_json' => null,
            'search_text' => $card->search_text,
            'study_status' => 'new',
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
            'created_at' => $card->created_at?->toJSON(),
            'updated_at' => $card->updated_at?->toJSON(),
            'deleted_at' => $card->deleted_at?->toJSON(),
        ], $entry->payload);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = $this->cardFor($this->signIn());
        $deleteCard = new DeleteCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $deleteCard->handle($card);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_no_ops_when_card_is_already_soft_deleted(): void
    {
        $card = $this->cardFor($this->signIn());

        $card->delete();

        $result = app(DeleteCardAction::class)->handle($card);

        $this->assertFalse($result->wasDeleted);
        $this->assertSame($card, $result->card);
        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_retains_card_media_and_review_events(): void
    {
        $card = $this->cardFor($this->signIn());
        $mediaAsset = MediaAsset::factory()->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $result = app(DeleteCardAction::class)->handle($card);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($card, $result->card);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
        ]);
    }
}
