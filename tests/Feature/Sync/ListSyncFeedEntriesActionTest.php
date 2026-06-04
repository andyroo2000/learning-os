<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_it_filters_entries_by_resource_type(): void
    {
        $user = User::factory()->create();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'resource_type' => 'deck',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            resourceType: ' card ',
        );

        $this->assertSame([$card->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertSame($deck->checkpoint, $result->currentCheckpoint);
        $this->assertSame($result->currentCheckpoint, $result->nextCheckpoint(0));
    }

    public function test_it_filters_entries_by_domain_resource_type_and_checkpoint_together(): void
    {
        $user = User::factory()->create();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $before->checkpoint,
            domain: 'flashcards',
            resourceType: 'card',
        );

        $this->assertSame([$after->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_domain_resource_type_resource_id_and_checkpoint_together(): void
    {
        $user = User::factory()->create();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'card-1',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $before->checkpoint,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: ' card-1 ',
        );

        $this->assertSame([$after->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_normalizes_filter_metadata_case(): void
    {
        $user = User::factory()->create();
        $resourceId = strtolower((string) Str::ulid());
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card_media',
            'resource_id' => $resourceId,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: ' MEDIA ',
            resourceType: ' CARD_MEDIA ',
            resourceId: ' '.strtoupper($resourceId).' ',
            operation: ' DELETE ',
        );

        $this->assertSame([$entry->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_operation(): void
    {
        $user = User::factory()->create();
        $create = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Create,
        ]);
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            operation: ' delete ',
        );

        $this->assertSame([$delete->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertSame($update->checkpoint, $result->currentCheckpoint);
        $this->assertSame($result->currentCheckpoint, $result->nextCheckpoint(0));
        $this->assertNotContains($create->checkpoint, $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_operation_and_checkpoint_together(): void
    {
        $user = User::factory()->create();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $before->checkpoint,
            operation: SyncFeedOperation::Delete->value,
        );

        $this->assertSame([$after->checkpoint], $result->entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_resource_scope_and_operation_together(): void
    {
        $user = User::factory()->create();
        $targetDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Delete,
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'asset',
            'resource_id' => 'asset-1',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: 'card-1',
            operation: SyncFeedOperation::Delete->value,
        );

        $this->assertSame([$targetDelete->checkpoint], $result->entries->pluck('checkpoint')->all());
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
            $this->assertNull($exception->resourceType());
            $this->assertNull($exception->resourceId());
            $this->assertNull($exception->operation());
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
            $this->assertNull($exception->resourceType());
            $this->assertNull($exception->resourceId());
            $this->assertNull($exception->operation());
        }
    }

    public function test_it_rejects_stale_checkpoints_against_the_operation_filtered_feed_window(): void
    {
        $user = User::factory()->create();
        $update = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);
        $oldestDelete = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $update->checkpoint,
                operation: SyncFeedOperation::Delete->value,
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($update->checkpoint, $exception->afterCheckpoint());
            $this->assertSame($oldestDelete->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertNull($exception->domain());
            $this->assertNull($exception->resourceType());
            $this->assertNull($exception->resourceId());
            $this->assertSame(SyncFeedOperation::Delete->value, $exception->operation());
        }
    }

    public function test_it_rejects_stale_checkpoints_against_the_resource_type_filtered_feed_window(): void
    {
        $user = User::factory()->create();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $deck->checkpoint,
                domain: 'flashcards',
                resourceType: 'card',
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($deck->checkpoint, $exception->afterCheckpoint());
            $this->assertSame($oldestCard->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertSame('flashcards', $exception->domain());
            $this->assertSame('card', $exception->resourceType());
            $this->assertNull($exception->resourceId());
        }
    }

    public function test_it_rejects_stale_checkpoints_against_the_resource_type_only_filtered_feed_window(): void
    {
        $user = User::factory()->create();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $deck->checkpoint,
                resourceType: 'card',
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($deck->checkpoint, $exception->afterCheckpoint());
            $this->assertSame($oldestCard->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertNull($exception->domain());
            $this->assertSame('card', $exception->resourceType());
            $this->assertNull($exception->resourceId());
        }
    }

    public function test_it_rejects_stale_checkpoints_against_the_resource_id_filtered_feed_window(): void
    {
        $user = User::factory()->create();
        $otherCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        $oldestTarget = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        try {
            app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                afterCheckpoint: $otherCard->checkpoint,
                domain: 'flashcards',
                resourceType: 'card',
                resourceId: 'card-1',
            );

            $this->fail('Expected StaleSyncFeedCheckpointException was not thrown.');
        } catch (StaleSyncFeedCheckpointException $exception) {
            $this->assertSame($otherCard->checkpoint, $exception->afterCheckpoint());
            $this->assertSame($oldestTarget->checkpoint, $exception->oldestAvailableCheckpoint());
            $this->assertSame('flashcards', $exception->domain());
            $this->assertSame('card', $exception->resourceType());
            $this->assertSame('card-1', $exception->resourceId());
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
        $this->assertGreaterThan(
            $result->entries->last()->checkpoint,
            $result->currentCheckpoint,
        );
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

    public function test_domain_filtered_partial_pages_keep_next_checkpoint_at_the_page_boundary(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        SyncFeedEntry::factory()->create([
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

        $this->assertTrue($result->hasMore);
        $this->assertSame($media->checkpoint, $result->currentCheckpoint);
        $this->assertSame($secondFlashcards->checkpoint, $result->nextCheckpoint(0));
    }

    public function test_resource_type_filtered_partial_pages_keep_next_checkpoint_at_the_page_boundary(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $secondCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: 'flashcards',
            resourceType: 'card',
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertTrue($result->hasMore);
        $this->assertSame($deck->checkpoint, $result->currentCheckpoint);
        $this->assertSame($secondCard->checkpoint, $result->nextCheckpoint(0));
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

    public function test_it_rejects_blank_resource_type_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_type must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceType: ' ',
        );
    }

    public function test_it_rejects_blank_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: ' ',
        );
    }

    public function test_it_rejects_blank_operation_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed operation must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            operation: ' ',
        );
    }

    public function test_it_rejects_unknown_operation_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed operation must be one of: create, update, delete.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            operation: 'patch',
        );
    }

    public function test_it_requires_domain_and_resource_type_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceId: 'card-1',
        );
    }

    public function test_it_requires_domain_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceType: 'card',
            resourceId: 'card-1',
        );
    }

    public function test_it_requires_resource_type_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: 'flashcards',
            resourceId: 'card-1',
        );
    }
}
