<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ListSyncFeedEntriesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_user_feed_entries_after_a_checkpoint_in_replay_order(): void
    {
        $user = User::factory()->create();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $first->checkpoint,
        );

        $this->assertSame([
            $second->checkpoint,
            $third->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($third->checkpoint, $result->currentCheckpoint);
        $this->assertSame($third->checkpoint, $result->nextCheckpoint($first->checkpoint));
    }

    public function test_it_lists_the_full_user_feed_when_checkpoint_is_zero(): void
    {
        $user = User::factory()->create();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: 0,
        );

        $this->assertSame([
            $first->checkpoint,
            $second->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
    }

    public function test_it_scopes_entries_to_the_requested_user(): void
    {
        $user = User::factory()->create();
        $visible = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        SyncFeedEntry::factory()->create(['user_id' => User::factory()->create()->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertSame([$visible->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_domain(): void
    {
        $user = User::factory()->create();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: ' flashcards ',
        );

        $this->assertSame([$flashcards->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($result->currentCheckpoint, $result->nextCheckpoint(0));
    }

    public function test_it_filters_by_domain_and_checkpoint_together(): void
    {
        $user = User::factory()->create();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $before->checkpoint,
            domain: 'flashcards',
        );

        $this->assertSame([$after->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_rejects_stale_checkpoints_before_the_oldest_available_user_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: 4,
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame('Sync checkpoint is stale; perform a full resource resync.', $exception->getMessage());
            $this->assertSame(4, $exception->afterCheckpoint());
            $this->assertSame($oldest->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertNull($exception->domain());
            $this->assertSame(StaleSyncFeedCheckpointException::REASON, $exception->reason());
            $this->assertSame(StaleSyncFeedCheckpointException::REQUIRED_ACTION, $exception->requiredAction());
        }
    }

    public function test_it_rejects_stale_checkpoints_against_the_domain_filtered_feed_window(): void
    {
        $user = User::factory()->create();
        $media = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);
        $oldestFlashcard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $this->assertTrue(
            $media->checkpoint < $oldestFlashcard->checkpoint,
            'The media checkpoint must predate the oldest flashcard checkpoint for this stale-domain test.',
        );

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $media->checkpoint,
                domain: 'flashcards',
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($media->checkpoint, $exception->afterCheckpoint());
            $this->assertSame($oldestFlashcard->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertSame('flashcards', $exception->domain());
        }
    }

    public function test_it_allows_checkpoints_equal_to_the_oldest_available_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);
        $next = SyncFeedEntry::factory()->create([
            'checkpoint' => 6,
            'user_id' => $user->id,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $oldest->checkpoint,
        );

        $this->assertSame([$next->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_allows_checkpoint_zero_before_the_oldest_available_entry(): void
    {
        $user = User::factory()->create();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: 0,
        );

        $this->assertSame([$oldest->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_allows_nonzero_checkpoints_when_the_filtered_scope_has_no_entries(): void
    {
        $user = User::factory()->create();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $media->checkpoint,
            domain: 'flashcards',
        );

        $this->assertTrue($result->entries->isEmpty());
        $this->assertFalse($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($media->checkpoint, $result->nextCheckpoint($media->checkpoint));
    }

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $result->entries);
        $this->assertTrue($result->hasMore);
        $this->assertSame(
            $result->entries->last()->checkpoint,
            $result->nextCheckpoint(0),
        );
    }

    public function test_it_uses_the_default_page_size_when_none_is_provided(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertCount(CursorPagination::DEFAULT_PAGE_SIZE, $result->entries);
        $this->assertTrue($result->hasMore);
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(0),
        );

        $this->assertCount(1, $result->entries);
        $this->assertTrue($result->hasMore);
    }

    public function test_it_reports_no_more_entries_when_the_page_is_exactly_full(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertCount(2, $result->entries);
        $this->assertFalse($result->hasMore);
    }

    public function test_it_reports_no_more_entries_when_a_domain_filtered_page_is_exactly_full(): void
    {
        $user = User::factory()->create();
        $firstFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: 'flashcards',
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame([
            $firstFlashcards->checkpoint,
            $secondFlashcards->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($result->currentCheckpoint, $result->nextCheckpoint(0));
    }

    public function test_it_rejects_non_positive_user_id(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Sync feed user ID must be a positive integer.');

        app(ListSyncFeedEntriesAction::class)->handle(0);
    }

    public function test_it_rejects_negative_checkpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed checkpoint must be zero or greater.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            afterCheckpoint: -1,
        );
    }

    public function test_it_rejects_blank_domain_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed domain must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: ' ',
        );
    }
}
