<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class MediaAssetSyncPayloadTest extends TestCase
{
    public function test_it_uses_client_facing_media_manifest_keys(): void
    {
        $mediaAsset = new MediaAsset;
        $mediaAsset->setRawAttributes([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
            'created_at' => Carbon::parse('2026-05-27T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-27T09:15:00Z'),
        ], sync: true);

        $payload = MediaAssetSyncPayload::fromMediaAsset($mediaAsset);

        $this->assertSame([
            'id',
            'url',
            'mime_type',
            'size_bytes',
            'checksum_sha256',
            'original_filename',
            'created_at',
            'updated_at',
        ], array_keys($payload));
        $this->assertSame('https://cdn.example.test/uploads/example.jpg', $payload['url']);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('public_url', $payload);
    }
}
