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
        $asset = MediaAsset::factory()
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
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
        $asset = MediaAsset::factory()->make();

        $this->expectException(InvalidArgumentException::class);

        $asset->public_url = 'not-a-url';
    }

    public function test_empty_public_url_is_stored_as_null(): void
    {
        $asset = MediaAsset::factory()->make();
        $asset->public_url = '   ';
        $asset->save();

        $this->assertNull($asset->public_url);

        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'public_url' => null,
        ]);
    }

    public function test_public_url_must_be_assigned_explicitly(): void
    {
        $asset = MediaAsset::query()->create([
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $this->assertNull($asset->public_url);

        $asset->public_url = 'https://cdn.example.test/uploads/example.jpg';
        $asset->save();

        $this->assertSame('https://cdn.example.test/uploads/example.jpg', $asset->fresh()->public_url);
    }

    public function test_public_url_longer_than_column_limit_is_rejected(): void
    {
        $asset = MediaAsset::factory()->make();

        $this->expectException(InvalidArgumentException::class);

        $asset->public_url = 'https://cdn.example.test/'.str_repeat('a', 2049);
    }

    public function test_public_url_at_column_limit_is_accepted(): void
    {
        $asset = MediaAsset::factory()->make();
        $url = 'https://cdn.example.test/'.str_repeat('a', 2023);

        $asset->public_url = $url;
        $asset->save();

        $this->assertSame(2048, mb_strlen($url));
        $this->assertSame($url, $asset->fresh()->public_url);
    }

    public function test_original_filename_is_normalized_to_a_basename(): void
    {
        $asset = MediaAsset::factory()->create([
            'original_filename' => 'C:\\Users\\andrew\\Downloads/example.jpg',
        ]);

        $this->assertSame('example.jpg', $asset->original_filename);

        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'original_filename' => 'example.jpg',
        ]);
    }

    public function test_raw_original_filename_reads_as_normalized(): void
    {
        $asset = MediaAsset::factory()->create();

        DB::table('media_assets')
            ->where('id', $asset->id)
            ->update(['original_filename' => 'C:\\Users\\andrew\\Downloads/example.jpg']);

        $this->assertSame('example.jpg', $asset->fresh()->original_filename);
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
        $asset = MediaAsset::factory()->make();

        $this->expectException(InvalidArgumentException::class);

        $asset->public_url = 'ftp://cdn.example.test/uploads/example.jpg';
    }
}
