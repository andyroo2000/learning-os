<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class MediaAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_assets_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('media_assets', [
            'id',
            'disk',
            'path',
            'public_url',
            'mime_type',
            'size_bytes',
            'checksum_sha256',
            'original_filename',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_media_asset_can_be_created(): void
    {
        $asset = MediaAsset::factory()->create([
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $this->assertIsString($asset->id);
        $this->assertTrue(Str::isUlid($asset->id));

        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);
    }

    public function test_invalid_public_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaAsset::factory()->create([
            'public_url' => 'not-a-url',
        ]);
    }

    public function test_empty_public_url_is_stored_as_null(): void
    {
        $asset = MediaAsset::factory()->create([
            'public_url' => '   ',
        ]);

        $this->assertNull($asset->public_url);

        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'public_url' => null,
        ]);
    }

    public function test_raw_empty_public_url_reads_as_null(): void
    {
        $asset = MediaAsset::factory()->create();

        DB::table('media_assets')
            ->where('id', $asset->id)
            ->update(['public_url' => '']);

        $this->assertNull($asset->fresh()->public_url);
    }

    public function test_non_http_public_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaAsset::factory()->create([
            'public_url' => 'ftp://cdn.example.test/uploads/example.jpg',
        ]);
    }
}
