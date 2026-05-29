<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CreateMediaAssetActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_media_asset_for_a_user(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
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
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: $id,
            ),
        );

        $this->assertSame(strtolower($id), $mediaAsset->id);

        $this->assertDatabaseHas('media_assets', [
            'id' => strtolower($id),
            'user_id' => $user->id,
        ]);
    }

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

        $firstMediaAsset = app(CreateMediaAssetAction::class)->handle($data);
        $secondMediaAsset = app(CreateMediaAssetAction::class)->handle($data);

        $this->assertTrue($secondMediaAsset->is($firstMediaAsset));
        $this->assertDatabaseCount('media_assets', 1);
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

        // Matching race metadata returns the existing row; mismatching race metadata rejects below.
        Event::listen('eloquent.creating: '.MediaAsset::class, function (MediaAsset $mediaAsset) use ($id, $now, $user): void {
            if ($mediaAsset->id !== $id) {
                return;
            }

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
        });

        try {
            $mediaAsset = app(CreateMediaAssetAction::class)->handle(
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
        } finally {
            Event::forget('eloquent.creating: '.MediaAsset::class);
        }

        $this->assertSame($id, $mediaAsset->id);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_rejects_concurrent_provided_ulid_insert_with_different_metadata(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $now = now();

        Event::listen('eloquent.creating: '.MediaAsset::class, function (MediaAsset $mediaAsset) use ($id, $now, $user): void {
            if ($mediaAsset->id !== $id) {
                return;
            }

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
        });

        try {
            $this->expectException(MediaAssetConflictException::class);
            $this->expectExceptionMessage('Media asset ID already exists with different metadata.');

            app(CreateMediaAssetAction::class)->handle(
                CreateMediaAssetData::fromInput(
                    userId: $user->id,
                    disk: 'media',
                    path: 'uploads/race.jpg',
                    mimeType: 'image/jpeg',
                    sizeBytes: 123_456,
                    id: $id,
                ),
            );
        } finally {
            Event::forget('eloquent.creating: '.MediaAsset::class);
        }
    }

    public function test_it_trims_inputs_and_stores_blank_optional_values_as_null(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: '  media  ',
                path: '  uploads/example.jpg  ',
                publicUrl: '   ',
                mimeType: '  image/jpeg  ',
                sizeBytes: 123_456,
                checksumSha256: '   ',
                originalFilename: '  C:\\Users\\andrew\\Downloads/example.jpg  ',
            ),
        );

        $this->assertSame('media', $mediaAsset->disk);
        $this->assertSame('uploads/example.jpg', $mediaAsset->path);
        $this->assertNull($mediaAsset->public_url);
        $this->assertSame('image/jpeg', $mediaAsset->mime_type);
        $this->assertNull($mediaAsset->checksum_sha256);
        $this->assertSame('example.jpg', $mediaAsset->original_filename);
    }

    public function test_it_stores_original_filename_dot_segments_as_null(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                originalFilename: '..',
            ),
        );

        $this->assertNull($mediaAsset->original_filename);

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
            'original_filename' => null,
        ]);
    }

    public function test_it_stores_checksums_in_lowercase(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('A', 64),
            ),
        );

        $this->assertSame(str_repeat('a', 64), $mediaAsset->checksum_sha256);
    }

    public function test_it_strips_mime_type_parameters(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.html',
                mimeType: '  TEXT/HTML; charset=utf-8  ',
                sizeBytes: 123_456,
            ),
        );

        $this->assertSame('text/html', $mediaAsset->mime_type);
    }

    public function test_it_rejects_blank_disk(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset disk is required.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: '   ',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_disk_longer_than_column_limit(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset disk must not exceed '.MediaAsset::MAX_DISK_LENGTH.' characters.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: str_repeat('a', MediaAsset::MAX_DISK_LENGTH + 1),
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_unsupported_disk(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset disk is not supported.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'private',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_invalid_user_id(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Media asset user ID must be a positive integer.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: 0,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_leaves_missing_positive_user_id_conflicts_to_the_database(): void
    {
        $this->expectException(QueryException::class);

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: 999,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_blank_path(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset path is required.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: '   ',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_path_longer_than_column_limit(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset path must not exceed '.MediaAsset::MAX_PATH_LENGTH.' characters.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: str_repeat('a', MediaAsset::MAX_PATH_LENGTH + 1),
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_path_traversal_sequences(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset path must not contain traversal sequences.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/../example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_absolute_paths(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset path must be relative.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: '/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_windows_absolute_paths(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset path must be relative.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'C:\\uploads\\example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_allows_double_dots_inside_path_segments(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/my..photo.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );

        $this->assertSame('uploads/my..photo.jpg', $mediaAsset->path);
    }

    public function test_it_rejects_blank_mime_type(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type is required.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: '   ',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_mime_type_longer_than_column_limit(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type must not exceed '.MediaAsset::MAX_MIME_TYPE_LENGTH.' characters.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/'.str_repeat('a', MediaAsset::MAX_MIME_TYPE_LENGTH),
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_invalid_mime_type(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type must include a type and subtype.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_mime_type_without_a_subtype(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type must include a type and subtype.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_mime_type_without_a_type(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type must include a type and subtype.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: '/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_mime_type_with_extra_parts(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset MIME type must include a type and subtype.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg/extra',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_empty_size(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset size must be at least 1 byte.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 0,
            ),
        );
    }

    public function test_it_rejects_negative_size(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset size must be at least 1 byte.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: -1,
            ),
        );
    }

    public function test_it_accepts_maximum_size(): void
    {
        $user = User::factory()->create();

        $mediaAsset = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: MediaAsset::MAX_SIZE_BYTES,
            ),
        );

        $this->assertSame(MediaAsset::MAX_SIZE_BYTES, $mediaAsset->fresh()->size_bytes);
    }

    public function test_it_rejects_invalid_checksum(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset checksum must be a 64-character SHA-256 hex digest.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: 'not-a-checksum',
            ),
        );
    }

    public function test_it_rejects_short_checksum(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset checksum must be a 64-character SHA-256 hex digest.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('a', 63),
            ),
        );
    }

    public function test_it_rejects_non_hex_checksum(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset checksum must be a 64-character SHA-256 hex digest.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('g', 64),
            ),
        );
    }

    public function test_it_rejects_original_filename_longer_than_column_limit(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset original filename must not exceed '.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH.' characters.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                originalFilename: str_repeat('a', MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH + 1),
            ),
        );
    }

    public function test_it_rejects_invalid_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must be a valid URL.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'not-a-url',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_non_http_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must use the http or https scheme.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'ftp://cdn.example.test/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_localhost_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://localhost/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_private_ip_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://10.0.0.1/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_decimal_ip_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://2130706433/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_hex_ip_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://0x7f000001/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_zero_address_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://0.0.0.0/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_link_local_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://169.254.169.254/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_ipv6_loopback_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[::1]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_ipv4_mapped_ipv6_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[::ffff:10.0.0.1]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_non_canonical_ipv4_mapped_ipv6_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[0:0:0:0:0:ffff:a00:1]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_siit_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[::ffff:0:10.0.0.1]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_nat64_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[64:ff9b::10.0.0.1]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_6to4_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[2002:0a00:0001::]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_teredo_public_url(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not use a private or reserved host.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: 'https://[2001:0000:0a00:0001::]/uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_public_url_longer_than_column_limit(): void
    {
        $user = User::factory()->create();
        $prefix = 'https://cdn.example.test/';
        $url = $prefix.str_repeat('a', MediaAsset::MAX_PUBLIC_URL_LENGTH - mb_strlen($prefix) + 1);

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset public URL must not exceed '.MediaAsset::MAX_PUBLIC_URL_LENGTH.' characters.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                publicUrl: $url,
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_duplicate_disk_path_conflicts_without_a_provided_ulid(): void
    {
        $user = User::factory()->create();

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset already exists.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_duplicate_disk_path_conflicts_with_a_provided_ulid(): void
    {
        $user = User::factory()->create();

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset already exists.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: strtolower((string) Str::ulid()),
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset ID must be a valid ULID.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: 'not-a-ulid',
            ),
        );
    }
}
