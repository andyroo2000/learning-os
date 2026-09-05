<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetMimeTypeValidationApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_rejects_trimmed_malformed_mime_types_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/media-assets', [
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => '  image  ',
                'size_bytes' => 123_456,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mime_type']);

        $this->assertDatabaseCount('media_assets', 0);
    }
}
