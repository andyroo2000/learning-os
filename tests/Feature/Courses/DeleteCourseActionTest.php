<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Actions\DeleteCourseAction;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class DeleteCourseActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_course_and_records_sync_feed_entry(): void
    {
        $course = Course::factory()->for($this->signIn())->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $result = app(DeleteCourseAction::class)->handle($course);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($course, $result->course);
        $this->assertSoftDeleted('courses', [
            'id' => $course->id,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertNotNull($course->deleted_at);
        $this->assertSame($course->user_id, $entry->user_id);
        $this->assertSame(CourseSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CourseSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($course->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame(CourseSyncPayload::fromCourse($course), $entry->payload);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $course = Course::factory()->for($this->signIn())->create();
        $deleteCourse = new DeleteCourseAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
            deleteDeck: app(DeleteDeckAction::class),
        );

        try {
            $deleteCourse->handle($course);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_soft_deletes_course_scoped_decks_and_cards_before_course_tombstone(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $firstCourseDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $secondCourseDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $standaloneDeck = Deck::factory()->create(['user_id' => $user->id]);
        $otherCourseDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $otherCourse->id,
        ]);
        $firstCard = Card::factory()->for($firstCourseDeck)->create();
        $secondCard = Card::factory()->for($secondCourseDeck)->create();
        $standaloneCard = Card::factory()->for($standaloneDeck)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();

        $result = app(DeleteCourseAction::class)->handle($course);

        $this->assertTrue($result->wasDeleted);
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertSoftDeleted('decks', ['id' => $firstCourseDeck->id]);
        $this->assertSoftDeleted('decks', ['id' => $secondCourseDeck->id]);
        $this->assertSoftDeleted('cards', ['id' => $firstCard->id]);
        $this->assertSoftDeleted('cards', ['id' => $secondCard->id]);
        $this->assertDatabaseHas('decks', [
            'id' => $standaloneDeck->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('decks', [
            'id' => $otherCourseDeck->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $standaloneCard->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $otherCourseCard->id,
            'deleted_at' => null,
        ]);

        $entries = SyncFeedEntry::query()
            ->orderBy('checkpoint')
            ->get();

        $this->assertCount(5, $entries);
        $this->assertSame(['card', 'deck', 'card', 'deck', 'course'], $entries->pluck('resource_type')->all());

        $courseEntry = $entries->last();
        $this->assertSame(CourseSyncPayload::DOMAIN, $courseEntry->domain);
        $this->assertSame(CourseSyncPayload::RESOURCE_TYPE, $courseEntry->resource_type);
        $this->assertSame($course->id, $courseEntry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $courseEntry->operation);
    }

    public function test_it_rolls_back_course_deck_and_card_deletes_when_child_feed_recording_fails(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();
        $recordSyncFeedEntry = new class extends RecordSyncFeedEntryAction
        {
            public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
            {
                throw new RuntimeException('Sync feed failed.');
            }
        };
        $deleteCourse = new DeleteCourseAction(
            recordSyncFeedEntry: $recordSyncFeedEntry,
            deleteDeck: new DeleteDeckAction($recordSyncFeedEntry),
        );

        try {
            $deleteCourse->handle($course);

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_no_ops_when_the_course_is_already_soft_deleted(): void
    {
        $course = Course::factory()->for($this->signIn())->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $course->delete();
            $originalDeletedAt = $course->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteCourseAction::class)->handle($course);

            $this->assertFalse($result->wasDeleted);
            $this->assertSame($course, $result->course);
            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
