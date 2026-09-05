<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class CreateMediaAssetActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_creates_a_media_asset_for_a_user(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://cdn.example.test/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('a', 64),
                originalFilename: 'example.jpg',
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($mediaAsset->id));
        $this->assertSame(strtolower($mediaAsset->id), $mediaAsset->id);

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset, SyncFeedOperation::Create);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $mediaAsset->id);

        $this->assertDatabaseHas('media_assets', [
            'id' => strtolower($id),
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset, SyncFeedOperation::Create);
    }

    public function test_it_normalizes_padded_provided_ulids_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: "  {$id}  ",
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $mediaAsset->id);

        $this->assertDatabaseHas('media_assets', [
            'id' => strtolower($id),
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertMediaAssetSyncPayloadRecorded($mediaAsset, SyncFeedOperation::Create);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $createMediaAsset = new CreateMediaAssetAction(
            recordMediaAssetSyncFeedEntry: new RecordMediaAssetSyncFeedEntryAction(
                recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
                {
                    public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                    {
                        throw new RuntimeException('Sync feed failed.');
                    }
                },
            ),
        );

        try {
            $createMediaAsset->handle(
                CreateMediaAssetData::fromInput(
                    userId: $user->id,
                    disk: 'media',
                    path: 'uploads/example.jpg',
                    mimeType: 'image/jpeg',
                    sizeBytes: 123_456,
                    id: $id,
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('media_assets', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
