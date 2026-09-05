<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

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
}
