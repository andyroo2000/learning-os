<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetSizeValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_array_size_bytes_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => ['123456'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size_bytes']);

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
}
