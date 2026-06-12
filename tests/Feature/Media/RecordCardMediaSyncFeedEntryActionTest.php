<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordCardMediaSyncFeedEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_canonical_card_media_sync_payloads(): void
    {
        $user = User::factory()->create();
        $createdAt = Carbon::parse('2026-05-29T11:14:00Z');
        $updatedAt = '2026-05-29 11:15:00';

        $entry = app(RecordCardMediaSyncFeedEntryAction::class)->handle(
            userId: $user->id,
            operation: SyncFeedOperation::Create,
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            deckId: '01jzq4szwqs0e6hd3m7x2s4ana',
            courseId: '01jzq4tpn3qt1zgs8c3x3tgz9h',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(CardMediaSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CardMediaSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame('01jzq4nny5xbnzw14q1g68b2yt:01jzq4rqm0psp2zk6426fx85m9', $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame([
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'media_asset_id' => '01jzq4rqm0psp2zk6426fx85m9',
            'deck_id' => '01jzq4szwqs0e6hd3m7x2s4ana',
            'course_id' => '01jzq4tpn3qt1zgs8c3x3tgz9h',
            'created_at' => '2026-05-29T11:14:00.000000Z',
            'updated_at' => '2026-05-29T11:15:00.000000Z',
        ], $entry->payload);
    }

    public function test_it_preserves_tombstone_null_timestamp_keys(): void
    {
        $user = User::factory()->create();

        $entry = app(RecordCardMediaSyncFeedEntryAction::class)->handle(
            userId: $user->id,
            operation: SyncFeedOperation::Delete,
            cardId: '01jzq4nny5xbnzw14q1g68b2yt',
            mediaAssetId: '01jzq4rqm0psp2zk6426fx85m9',
            deckId: null,
            courseId: null,
            createdAt: null,
            updatedAt: null,
        );

        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame([
            'card_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'media_asset_id' => '01jzq4rqm0psp2zk6426fx85m9',
            'deck_id' => null,
            'course_id' => null,
            'created_at' => null,
            'updated_at' => null,
        ], SyncFeedEntry::query()->sole()->payload);
    }
}
