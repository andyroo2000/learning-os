<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class RecordSyncFeedEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_sync_feed_entry_for_a_user(): void
    {
        $user = User::factory()->create();
        $serverRecordedAt = Carbon::parse('2026-06-03 09:15:00');
        Carbon::setTestNow($serverRecordedAt);

        try {
            $entry = $this->recordFeedEntry(
                RecordSyncFeedEntryData::fromInput(
                    userId: $user->id,
                    domain: 'flashcards',
                    resourceType: 'deck',
                    resourceId: 'deck_01',
                    operation: 'create',
                    payload: [
                        'id' => 'deck_01',
                        'name' => 'Biology',
                    ],
                ),
            );
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('deck', $entry->resource_type);
        $this->assertSame('deck_01', $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame($serverRecordedAt->toDateTimeString(), $entry->server_recorded_at->toDateTimeString());
        $this->assertSame([
            'id' => 'deck_01',
            'name' => 'Biology',
        ], $entry->payload);

        $this->assertDatabaseHas('sync_feed_entries', [
            'checkpoint' => $entry->checkpoint,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'deck_01',
            'operation' => 'create',
            'server_recorded_at' => '2026-06-03 09:15:00',
        ]);
    }

    public function test_it_trims_string_inputs(): void
    {
        $user = User::factory()->create();

        $entry = $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: ' flashcards ',
                resourceType: ' deck ',
                resourceId: ' deck_01 ',
                operation: ' update ',
            ),
        );

        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('deck', $entry->resource_type);
        $this->assertSame('deck_01', $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertDatabaseHas('sync_feed_entries', [
            'checkpoint' => $entry->checkpoint,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'deck_01',
        ]);
    }

    public function test_it_normalizes_metadata_case(): void
    {
        $user = User::factory()->create();

        $entry = $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: ' FLASHCARDS ',
                resourceType: ' CARD ',
                resourceId: ' DECK_01 ',
                operation: ' UPDATE ',
            ),
        );

        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('card', $entry->resource_type);
        $this->assertSame('deck_01', $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
    }

    public function test_it_allows_a_nullable_payload(): void
    {
        $user = User::factory()->create();

        $entry = $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: 'deck',
                resourceId: 'deck_01',
                operation: 'delete',
                payload: null,
            ),
        );

        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertNull($entry->payload);
    }

    public function test_it_records_multibyte_metadata_at_the_column_limit(): void
    {
        $user = User::factory()->create();
        $domain = str_repeat(mb_chr(0x754C), SyncFeedEntry::MAX_DOMAIN_LENGTH);
        $resourceType = str_repeat(mb_chr(0x7A2E), SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        $resourceId = str_repeat(mb_chr(0x8B58), SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);

        $entry = $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: $domain,
                resourceType: $resourceType,
                resourceId: $resourceId,
                operation: 'create',
            ),
        );

        $this->assertSame($domain, $entry->domain);
        $this->assertSame($resourceType, $entry->resource_type);
        $this->assertSame($resourceId, $entry->resource_id);
        $this->assertDatabaseHas('sync_feed_entries', [
            'checkpoint' => $entry->checkpoint,
            'domain' => $domain,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function test_it_rejects_non_positive_user_id(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Sync feed entry user ID must be a positive integer.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: 0,
                domain: 'flashcards',
                resourceType: 'deck',
                resourceId: 'deck_01',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_blank_required_metadata(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed domain is required.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: ' ',
                resourceType: 'deck',
                resourceId: 'deck_01',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_blank_resource_type(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_type is required.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: ' ',
                resourceId: 'deck_01',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_blank_resource_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id is required.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: 'deck',
                resourceId: ' ',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_overlong_required_metadata(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_type must not exceed 64 characters.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: str_repeat('a', SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1),
                resourceId: 'deck_01',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_overlong_domain(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed domain must not exceed 64 characters.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: str_repeat('a', SyncFeedEntry::MAX_DOMAIN_LENGTH + 1),
                resourceType: 'deck',
                resourceId: 'deck_01',
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_overlong_resource_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id must not exceed 64 characters.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: 'deck',
                resourceId: str_repeat('a', SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1),
                operation: 'create',
            ),
        );
    }

    public function test_it_rejects_unknown_operations(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed operation must be one of: create, update, delete.');

        $this->recordFeedEntry(
            RecordSyncFeedEntryData::fromInput(
                userId: $user->id,
                domain: 'flashcards',
                resourceType: 'deck',
                resourceId: 'deck_01',
                operation: 'patch',
            ),
        );
    }

    private function recordFeedEntry(RecordSyncFeedEntryData $data): SyncFeedEntry
    {
        return app(RecordSyncFeedEntryAction::class)->handle($data);
    }
}
