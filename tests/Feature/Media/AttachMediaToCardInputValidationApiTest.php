<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardInputValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_invalid_input(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => 'not-a-ulid',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_array_media_asset_input(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => ['not-a-ulid'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_blank_media_asset_id_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson("/api/cards/{$card->id}/media-assets", [
                'media_asset_id' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_missing_media_asset_input(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }
}
