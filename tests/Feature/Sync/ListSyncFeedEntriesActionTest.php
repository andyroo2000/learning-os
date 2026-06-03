<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
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

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $first->checkpoint,
        );

        $this->assertSame([
            $second->checkpoint,
            $third->checkpoint,
        ], $entries->pluck('checkpoint')->all());
    }

    public function test_it_lists_the_full_user_feed_when_checkpoint_is_zero(): void
    {
        $user = User::factory()->create();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: 0,
        );

        $this->assertSame([
            $first->checkpoint,
            $second->checkpoint,
        ], $entries->pluck('checkpoint')->all());
    }

    public function test_it_scopes_entries_to_the_requested_user(): void
    {
        $user = User::factory()->create();
        $visible = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        SyncFeedEntry::factory()->create(['user_id' => User::factory()->create()->id]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertSame([$visible->checkpoint], $entries->pluck('checkpoint')->all());
    }

    public function test_it_filters_entries_by_domain(): void
    {
        $user = User::factory()->create();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: ' flashcards ',
        );

        $this->assertSame([$flashcards->checkpoint], $entries->pluck('checkpoint')->all());
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

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            afterCheckpoint: $before->checkpoint,
            domain: 'flashcards',
        );

        $this->assertSame([$after->checkpoint], $entries->pluck('checkpoint')->all());
    }

    public function test_it_caps_the_page_size(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1),
        );

        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $entries);
    }

    public function test_it_uses_the_default_page_size_when_none_is_provided(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle($user->id);

        $this->assertCount(CursorPagination::DEFAULT_PAGE_SIZE, $entries);
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $entries = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(0),
        );

        $this->assertCount(1, $entries);
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
