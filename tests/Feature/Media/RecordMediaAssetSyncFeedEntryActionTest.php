<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordMediaAssetSyncFeedEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_create_entries_with_the_media_asset_manifest_payload(): void
    {
        $mediaAsset = $this->importedMediaAsset();

        $entry = app(RecordMediaAssetSyncFeedEntryAction::class)->handle(
            userId: $mediaAsset->user_id,
            operation: SyncFeedOperation::Create,
            mediaAsset: $mediaAsset,
        );

        $this->assertMediaAssetSyncEntryMatchesManifest($entry, $mediaAsset, SyncFeedOperation::Create);
    }

    public function test_it_records_delete_entries_with_the_media_asset_manifest_snapshot(): void
    {
        $mediaAsset = $this->importedMediaAsset();

        $entry = app(RecordMediaAssetSyncFeedEntryAction::class)->handle(
            userId: $mediaAsset->user_id,
            operation: SyncFeedOperation::Delete,
            mediaAsset: $mediaAsset,
        );

        $this->assertMediaAssetSyncEntryMatchesManifest($entry, $mediaAsset, SyncFeedOperation::Delete);
    }

    private function importedMediaAsset(): MediaAsset
    {
        $importJob = StudyImportJob::factory()->for(User::factory()->create())->create();
        $mediaAsset = MediaAsset::factory()->for($importJob->user)->create([
            'path' => 'study/imports/'.$importJob->id.'/0-word.mp3',
            'public_url' => 'https://cdn.example.test/media/word.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 987_654,
            'checksum_sha256' => str_repeat('b', 64),
            'original_filename' => 'word.mp3',
            'import_job_id' => $importJob->id,
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_media_ref' => '0',
            'source_filename' => 'word.mp3',
            'created_at' => Carbon::parse('2026-06-05 12:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-06-05 12:30:00', 'UTC'),
        ]);

        return $mediaAsset;
    }

    private function assertMediaAssetSyncEntryMatchesManifest(
        SyncFeedEntry $entry,
        MediaAsset $mediaAsset,
        SyncFeedOperation $operation,
    ): void {
        $this->assertSame($mediaAsset->user_id, $entry->user_id);
        $this->assertSame(MediaAssetSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(MediaAssetSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($mediaAsset->id, $entry->resource_id);
        $this->assertSame($operation, $entry->operation);
        $this->assertSame([
            'id' => $mediaAsset->id,
            'import_job_id' => $mediaAsset->import_job_id,
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_media_ref' => '0',
            'source_filename' => 'word.mp3',
            'url' => 'https://cdn.example.test/media/word.mp3',
            'content_url' => '/api/media-assets/'.$mediaAsset->id.'/content',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 987_654,
            'checksum_sha256' => str_repeat('b', 64),
            'original_filename' => 'word.mp3',
            'created_at' => '2026-06-05T12:00:00.000000Z',
            'updated_at' => '2026-06-05T12:30:00.000000Z',
        ], $entry->payload);
    }
}
