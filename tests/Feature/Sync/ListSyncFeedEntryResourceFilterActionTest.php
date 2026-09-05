<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListSyncFeedEntryResourceFilterActionTest extends TestCase
{
    use RefreshDatabase;

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
}
