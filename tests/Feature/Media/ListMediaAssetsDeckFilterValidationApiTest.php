<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsDeckFilterValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_blank_deck_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_a_malformed_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_an_array_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?deck_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }
}
