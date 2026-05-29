<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateMediaAssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_media_asset(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.size_bytes', 123_456)
            ->assertJsonPath('data.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.path')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'url',
                    'mime_type',
                    'size_bytes',
                    'checksum_sha256',
                    'original_filename',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('media_assets', [
            'id' => $response->json('data.id'),
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

    public function test_it_serializes_the_maximum_size_without_precision_loss(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/max-size.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => MediaAsset::MAX_JSON_SAFE_SIZE_BYTES,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.size_bytes', MediaAsset::MAX_JSON_SAFE_SIZE_BYTES);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $id = strtoupper((string) Str::ulid());

        $response = $this->postJson('/api/media-assets', [
            'id' => $id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('media_assets', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
        ]);
    }

    public function test_it_returns_existing_media_asset_for_idempotent_retries(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => $id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://cdn.example.test/uploads/example.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ];

        MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create($payload);

        $response = $this->postJson('/api/media-assets', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_normalizes_checksum_before_matching_idempotent_retries(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $checksum = str_repeat('aB', 32);
        $payload = [
            'id' => $id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'checksum_sha256' => $checksum,
            'original_filename' => null,
        ];

        // The model mutator lowercases persisted checksums, so idempotency compares
        // against the same normalized form the API stores for client-created assets.
        MediaAsset::factory()
            ->for($user)
            ->create($payload);

        $response = $this->postJson('/api/media-assets', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.checksum_sha256', strtolower($checksum));

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
            ]);

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/different.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Media asset ID already exists with different metadata.');

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_hides_client_provided_ulid_conflicts_for_other_users(): void
    {
        $this->signIn();
        $mediaAsset = MediaAsset::factory()->create([
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/different.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_hides_idempotent_retries_for_other_users_assets(): void
    {
        $mediaAsset = MediaAsset::factory()
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'public_url' => null,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => null,
                'original_filename' => null,
            ]);

        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'id' => $mediaAsset->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_rejects_storage_path_conflicts(): void
    {
        $user = $this->signIn();
        MediaAsset::factory()
            ->for($user)
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
            ]);

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Media asset already exists.');

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_hides_storage_path_conflicts_for_other_users(): void
    {
        MediaAsset::factory()
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
            ]);

        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_normalizes_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => ' media ',
            'path' => ' uploads/example.jpg ',
            'mime_type' => ' IMAGE/JPEG; charset=binary ',
            'size_bytes' => 123_456,
            'checksum_sha256' => strtoupper(str_repeat('a', 64)),
            'original_filename' => '../example.jpg',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.original_filename', 'example.jpg');

        $this->assertDatabaseHas('media_assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'checksum_sha256' => str_repeat('a', 64),
            'original_filename' => 'example.jpg',
        ]);
    }

    public function test_it_trims_original_filename_before_validation(): void
    {
        $this->signIn();
        $filename = str_repeat('a', MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH);

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'original_filename' => "  {$filename}  ",
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.original_filename', $filename);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'id' => 'not-a-ulid',
            'disk' => '   ',
            'path' => '   ',
            'mime_type' => '   ',
            'size_bytes' => 0,
            'public_url' => 'not-a-url',
            'checksum_sha256' => 'not-a-checksum',
            'original_filename' => str_repeat('a', MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH + 1),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'disk',
                'path',
                'mime_type',
                'size_bytes',
                'public_url',
                'checksum_sha256',
                'original_filename',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_size_larger_than_column_limit(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => '9223372036854775808',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size_bytes']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_private_public_urls(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://10.0.0.1/uploads/example.jpg',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['public_url']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_malformed_mime_types(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mime_type']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_maps_action_validation_errors_to_field_validation_errors(): void
    {
        $this->signIn();

        $this->app->instance(CreateMediaAssetAction::class, new class extends CreateMediaAssetAction
        {
            public function handle(CreateMediaAssetData $data): MediaAsset
            {
                throw new MediaAssetValidationException(
                    field: 'mime_type',
                    message: 'Media asset MIME type must include a type and subtype.',
                );
            }
        });

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mime_type']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_unknown_disks(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'private',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['disk']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_path_traversal_sequences(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/../example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_absolute_paths(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => '/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_windows_absolute_paths(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'C:\\uploads\\example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_allows_double_dots_inside_path_segments(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/my..photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('media_assets', [
            'path' => 'uploads/my..photo.jpg',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('media_assets', 0);
    }
}
