<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncFeedEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_feed_entries_table_has_contract_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('sync_feed_entries', [
            'checkpoint',
            'user_id',
            'domain',
            'resource_type',
            'resource_id',
            'operation',
            'server_recorded_at',
            'payload',
        ]));
    }

    public function test_sync_feed_entries_table_has_replay_filter_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('sync_feed_entries'));

        $this->assertNotEmpty($indexes->filter(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'domain', 'checkpoint']
        ));
        $this->assertNotEmpty($indexes->filter(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'resource_type', 'checkpoint']
        ));
        $this->assertNotEmpty($indexes->filter(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'domain', 'resource_type', 'checkpoint']
        ));
    }

    public function test_feed_entries_allocate_monotonic_checkpoints(): void
    {
        $first = SyncFeedEntry::factory()->create();
        $second = SyncFeedEntry::factory()->create();

        $this->assertIsInt($first->checkpoint);
        $this->assertIsInt($second->checkpoint);
        $this->assertGreaterThan($first->checkpoint, $second->checkpoint);
    }

    public function test_feed_entry_casts_numeric_identity_columns_to_integers(): void
    {
        $entry = new SyncFeedEntry;
        $entry->setRawAttributes([
            'checkpoint' => '123',
            'user_id' => '456',
        ]);

        $this->assertSame(123, $entry->checkpoint);
        $this->assertSame(456, $entry->user_id);
    }

    public function test_feed_entry_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $entry = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($entry->user->is($user));
    }

    public function test_deleting_user_cascades_to_feed_entries(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->count(3)->create(['user_id' => $user->id]);

        // User has no soft deletes today; this exercises the hard-delete database cascade.
        $user->delete();

        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_feed_entry_casts_replay_metadata(): void
    {
        $serverRecordedAt = now()->subMinute();

        $entry = SyncFeedEntry::factory()->create([
            'operation' => SyncFeedOperation::Delete,
            'server_recorded_at' => $serverRecordedAt,
            'payload' => [
                'id' => 'card_01',
                'deleted' => true,
            ],
        ]);

        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame($serverRecordedAt->toDateTimeString(), $entry->server_recorded_at->toDateTimeString());
        $this->assertSame([
            'id' => 'card_01',
            'deleted' => true,
        ], $entry->payload);
    }

    public function test_feed_entry_allows_nullable_payload(): void
    {
        $entry = SyncFeedEntry::factory()->create(['payload' => null]);

        $this->assertNull($entry->fresh()->payload);
    }

    public function test_sync_feed_operation_values_returns_all_cases(): void
    {
        $this->assertSame(['create', 'update', 'delete'], SyncFeedOperation::values());
    }
}
