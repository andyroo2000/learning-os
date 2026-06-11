<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
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
            'user_id',
            'import_job_id',
            'source_kind',
            'source_media_ref',
            'source_filename',
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
        $this->assertFalse(
            Schema::hasColumn('media_assets', 'deleted_at'),
            'Media assets are hard-deleted; export card-media counts intentionally have no media deleted_at filter.',
        );
    }

    public function test_media_assets_table_has_user_created_id_index(): void
    {
        $matchingIndexes = collect(Schema::getIndexes('media_assets'))
            ->filter(fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'created_at', 'id']);

        $this->assertNotEmpty($matchingIndexes);
    }

    public function test_import_source_fields_are_server_owned_but_explicitly_assignable(): void
    {
        $asset = new MediaAsset([
            'user_id' => User::factory()->create()->id,
            'import_job_id' => strtolower((string) Str::ulid()),
            'source_kind' => 'anki_import',
            'source_media_ref' => '42',
            'source_filename' => 'audio/example.mp3',
            'disk' => 'media',
            'path' => 'imports/example.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.mp3',
        ]);

        $this->assertNull($asset->import_job_id);
        $this->assertNull($asset->source_kind);
        $this->assertNull($asset->source_media_ref);
        $this->assertNull($asset->source_filename);

        $importJobId = strtolower((string) Str::ulid());
        $asset->import_job_id = $importJobId;
        $asset->source_kind = 'anki_import';
        $asset->source_media_ref = '42';
        $asset->source_filename = 'audio/example.mp3';

        $this->assertSame($importJobId, $asset->import_job_id);
        $this->assertSame('anki_import', $asset->source_kind);
        $this->assertSame('42', $asset->source_media_ref);
        $this->assertSame('audio/example.mp3', $asset->source_filename);
    }

    public function test_media_asset_can_be_created(): void
    {
        $user = User::factory()->create();
        $asset = MediaAsset::factory()
            ->for($user)
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
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);
    }

    public function test_media_asset_uses_string_non_incrementing_primary_keys(): void
    {
        $asset = MediaAsset::factory()->make();

        $this->assertSame('string', $asset->getKeyType());
        $this->assertFalse($asset->getIncrementing());
    }

    public function test_media_asset_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $asset = MediaAsset::factory()->for($user)->create();

        $this->assertTrue($asset->user->is($user));
    }

    public function test_allowed_media_disks_are_configured_filesystem_disks(): void
    {
        $configuredDisks = array_keys(config('filesystems.disks'));

        foreach (MediaAsset::ALLOWED_DISKS as $disk) {
            $this->assertContains($disk, $configuredDisks);
        }
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
        $user = User::factory()->create();

        $asset = MediaAsset::query()->create([
            'user_id' => $user->id,
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

        $asset->public_url = 'https://cdn.example.test/'.str_repeat('a', MediaAsset::MAX_PUBLIC_URL_LENGTH);
    }

    public function test_public_url_at_column_limit_is_accepted(): void
    {
        $asset = MediaAsset::factory()->make();
        $prefix = 'https://cdn.example.test/';
        $url = $prefix.str_repeat('a', MediaAsset::MAX_PUBLIC_URL_LENGTH - mb_strlen($prefix));

        $asset->public_url = $url;
        $asset->save();

        $this->assertSame(MediaAsset::MAX_PUBLIC_URL_LENGTH, mb_strlen($url));
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

    public function test_original_filename_dot_segments_are_stored_as_null(): void
    {
        $dotAsset = MediaAsset::factory()->create([
            'original_filename' => '.',
        ]);
        $dotDotAsset = MediaAsset::factory()->create([
            'original_filename' => '..',
        ]);

        $this->assertNull($dotAsset->original_filename);
        $this->assertNull($dotDotAsset->original_filename);

        $this->assertDatabaseHas('media_assets', [
            'id' => $dotAsset->id,
            'original_filename' => null,
        ]);
        $this->assertDatabaseHas('media_assets', [
            'id' => $dotDotAsset->id,
            'original_filename' => null,
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

    public function test_checksum_is_normalized_to_lowercase(): void
    {
        $asset = MediaAsset::factory()->create([
            'checksum_sha256' => str_repeat('A', 64),
        ]);

        $this->assertSame(str_repeat('a', 64), $asset->checksum_sha256);

        $this->assertDatabaseHas('media_assets', [
            'id' => $asset->id,
            'checksum_sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_raw_checksum_reads_as_lowercase(): void
    {
        $asset = MediaAsset::factory()->create();

        DB::table('media_assets')
            ->where('id', $asset->id)
            ->update(['checksum_sha256' => str_repeat('A', 64)]);

        $this->assertSame(str_repeat('a', 64), $asset->fresh()->checksum_sha256);
    }

    public function test_raw_empty_public_url_reads_as_null(): void
    {
        $asset = MediaAsset::factory()->create();

        DB::table('media_assets')
            ->where('id', $asset->id)
            ->update(['public_url' => '']);

        $this->assertNull($asset->fresh()->public_url);
    }

    public function test_raw_private_public_url_reads_without_revalidating_legacy_data(): void
    {
        $asset = MediaAsset::factory()->create();

        DB::table('media_assets')
            ->where('id', $asset->id)
            ->update(['public_url' => 'https://127.0.0.1/uploads/example.jpg']);

        $this->assertSame('https://127.0.0.1/uploads/example.jpg', $asset->fresh()->public_url);
    }

    public function test_non_http_public_url_is_rejected(): void
    {
        $asset = MediaAsset::factory()->make();

        $this->expectException(InvalidArgumentException::class);

        $asset->public_url = 'ftp://cdn.example.test/uploads/example.jpg';
    }

    public function test_private_public_url_host_is_rejected(): void
    {
        $asset = MediaAsset::factory()->make();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        $asset->public_url = 'https://127.0.0.1/uploads/example.jpg';
    }
}
