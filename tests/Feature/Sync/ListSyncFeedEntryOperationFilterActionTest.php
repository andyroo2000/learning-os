<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryOperationFilterActionTest extends TestCase
{
    use RefreshDatabase;

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
}
