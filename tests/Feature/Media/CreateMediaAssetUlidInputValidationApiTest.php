<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateMediaAssetUlidInputValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'id' => [strtolower((string) Str::ulid())],
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id']);

        $this->assertDatabaseCount('media_assets', 0);
    }
}
