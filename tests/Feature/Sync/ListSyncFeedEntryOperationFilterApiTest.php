<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryOperationFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_entries_by_operation(): void
    {
        $user = $this->signIn();
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

        $response = $this->getJson('/api/sync/feed?operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $create->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_operation_and_checkpoint_together(): void
    {
        $user = $this->signIn();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$before->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $after->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $before->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $before->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_operation_with_resource_scope(): void
    {
        $user = $this->signIn();
        $targetDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $targetUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
        ]);
        $otherDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'asset',
            'resource_id' => 'asset-1',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $targetDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $otherDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $otherDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $targetUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $otherDelete->checkpoint,
            ]);
    }
}
