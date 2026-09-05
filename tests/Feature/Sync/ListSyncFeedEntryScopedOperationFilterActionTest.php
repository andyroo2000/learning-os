<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryScopedOperationFilterActionTest extends TestCase
{
    use RefreshDatabase;

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
}
