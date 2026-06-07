<?php

namespace Tests\Unit\Resources\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Models\StudyImportJob;
use App\Http\Resources\Media\MediaAssetResource;
use Tests\TestCase;

class MediaAssetResourceTest extends TestCase
{
    public function test_media_asset_resource_serializes_import_source_metadata(): void
    {
        $mediaAsset = new MediaAsset;
        $mediaAsset->setRawAttributes([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
            'import_job_id' => '01k1j8n4st9y2aqj9b43r1dz0e',
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_media_ref' => '0',
            'source_filename' => 'word.mp3',
            'disk' => 'media',
            'path' => 'uploads/word.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 1234,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'word.mp3',
        ], sync: true);

        $resource = MediaAssetResource::make($mediaAsset)->resolve();

        $this->assertSame('01k1j8n4st9y2aqj9b43r1dz0e', $resource['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $resource['source_kind']);
        $this->assertSame('0', $resource['source_media_ref']);
        $this->assertSame('word.mp3', $resource['source_filename']);
        $this->assertArrayNotHasKey('disk', $resource);
        $this->assertArrayNotHasKey('path', $resource);
    }
}
