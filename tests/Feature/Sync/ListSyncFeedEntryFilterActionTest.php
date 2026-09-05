<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryFilterActionTest extends TestCase
{
    use RefreshDatabase;

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
}
