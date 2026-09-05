<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateMediaAssetRetryActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_returns_existing_media_asset_when_provided_ulid_is_retried(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $data = CreateMediaAssetData::fromInput(
            userId: $user->id,
            disk: 'media',
            path: 'uploads/example.jpg',
            publicUrl: 'https://cdn.example.test/uploads/example.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 123_456,
            checksumSha256: str_repeat('A', 64),
            originalFilename: 'example.jpg',
            id: $id,
        );

        $firstResult = app(CreateMediaAssetAction::class)->handle($data);
        $secondResult = app(CreateMediaAssetAction::class)->handle($data);

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertTrue($secondResult->mediaAsset->is($firstResult->mediaAsset));
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_rejects_provided_ulid_retry_with_different_metadata(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset ID already exists with different metadata.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/different.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );
    }

    public function test_it_rejects_provided_ulid_retry_with_different_public_url(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://cdn.example.test/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset ID already exists with different metadata.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://cdn.example.test/uploads/different.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );
    }

    public function test_it_rejects_provided_ulid_retry_for_a_different_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $firstUser->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset ID already exists with different metadata.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $secondUser->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );
    }

    public function test_it_returns_existing_media_asset_when_a_concurrent_provided_ulid_insert_wins_the_race(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $now = now();
        $createMediaAsset = new CreateMediaAssetAction(
            recordMediaAssetSyncFeedEntry: app(RecordMediaAssetSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateMediaAssetData $data) use ($id, $now, $user): void {
                $this->assertSame($id, $data->id);

                DB::table('media_assets')->insert([
                    'id' => $id,
                    'user_id' => $user->id,
                    'disk' => 'media',
                    'path' => 'uploads/race.jpg',
                    'public_url' => 'https://cdn.example.test/uploads/race.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 123_456,
                    'checksum_sha256' => str_repeat('a', 64),
                    'original_filename' => 'race.jpg',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            },
        );

        $result = $createMediaAsset->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/race.jpg',
                publicUrl: 'https://cdn.example.test/uploads/race.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('A', 64),
                originalFilename: 'race.jpg',
                id: $id,
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertFalse($result->wasCreated);
        $this->assertSame($id, $mediaAsset->id);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_concurrent_provided_ulid_insert_with_different_metadata(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $now = now();
        $createMediaAsset = new CreateMediaAssetAction(
            recordMediaAssetSyncFeedEntry: app(RecordMediaAssetSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateMediaAssetData $data) use ($id, $now, $user): void {
                $this->assertSame($id, $data->id);

                DB::table('media_assets')->insert([
                    'id' => $id,
                    'user_id' => $user->id,
                    'disk' => 'media',
                    'path' => 'uploads/different-race.jpg',
                    'public_url' => null,
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 123_456,
                    'checksum_sha256' => null,
                    'original_filename' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            },
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset ID already exists with different metadata.');

        $createMediaAsset->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/race.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );
    }
}
