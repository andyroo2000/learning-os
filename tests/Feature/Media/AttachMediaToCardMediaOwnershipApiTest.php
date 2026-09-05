<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachMediaToCardMediaOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_missing_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => strtolower((string) Str::ulid()),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_returns_not_found_for_another_users_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->create();
        Log::spy();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'media asset')
                && ($context['exception'] ?? null) instanceof MediaOwnershipException);

        $this->assertDatabaseCount('card_media', 0);
    }
}
