<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListSyncFeedEntryQueryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_replay_uses_one_checkpoint_window_query(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'media_asset',
        ]);
        $firstCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $secondCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = app(ListSyncFeedEntriesAction::class)->handle(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: 'card',
                pageSize: CursorPageSize::fromPerPage(2),
            );

            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $syncFeedQueries = $queries->filter(
            fn (array $query): bool => str_contains((string) $query['query'], 'sync_feed_entries')
        )->values();

        $this->assertSame([
            $firstCard->checkpoint,
            $secondCard->checkpoint,
        ], $result->entries->pluck('checkpoint')->all());
        $checkpointWindowQueries = $syncFeedQueries->filter(function (array $query): bool {
            $sql = strtolower((string) $query['query']);

            return str_contains($sql, 'min(checkpoint)')
                && str_contains($sql, 'max(checkpoint)');
        })->values();

        $this->assertCount(
            1,
            $checkpointWindowQueries,
            'Filtered sync replay should use one min/max checkpoint query.',
        );
    }
}
