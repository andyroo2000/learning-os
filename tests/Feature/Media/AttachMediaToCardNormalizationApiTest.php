<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_padded_uppercase_media_asset_id_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson("/api/cards/{$card->id}/media-assets", [
                'media_asset_id' => '  '.strtoupper($mediaAsset->id).'  ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_it_trims_media_asset_id_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson("/api/cards/{$card->id}/media-assets", [
                'media_asset_id' => "  {$mediaAsset->id}  ",
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_it_lowercases_media_asset_id_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson("/api/cards/{$card->id}/media-assets", [
                'media_asset_id' => strtoupper($mediaAsset->id),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }
}
