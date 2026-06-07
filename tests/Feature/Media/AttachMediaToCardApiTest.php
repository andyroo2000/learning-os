<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Models\User;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Media\Concerns\UsesMediaRateLimitOverrides;
use Tests\TestCase;

class AttachMediaToCardApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesMediaRateLimitOverrides;

    public function test_it_attaches_media_to_a_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => strtoupper($mediaAsset->id),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id)
            ->assertJsonPath('data.media_assets.0.url', 'https://cdn.example.test/uploads/example.jpg')
            ->assertJsonPath('data.media_assets.0.content_url', "/api/media-assets/{$mediaAsset->id}/content")
            ->assertJsonPath('data.media_assets.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.media_assets.0.size_bytes', 123_456)
            ->assertJsonPath('data.media_assets.0.checksum_sha256', str_repeat('a', 64))
            ->assertJsonPath('data.media_assets.0.original_filename', 'example.jpg')
            ->assertJsonMissingPath('data.media_assets.0.disk')
            ->assertJsonMissingPath('data.media_assets.0.path')
            ->assertJsonMissingPath('data.media_assets.0.url_expires_at');

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

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

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.media_assets')
            ->assertJsonPath('data.media_assets.0.url', null)
            ->assertJsonPath('data.media_assets.0.content_url', "/api/media-assets/{$mediaAsset->id}/content");

        $this->assertDatabaseCount('card_media', 1);
    }

    public function test_it_preserves_existing_attachments_when_adding_another_media_asset(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $firstMediaAsset = MediaAsset::factory()->for($user)->create();
        $secondMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.jpg')
            ->create();

        $card->mediaAssets()->attach($firstMediaAsset->id);

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $secondMediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.media_assets')
            ->assertJsonFragment(['id' => $firstMediaAsset->id])
            ->assertJsonFragment(['id' => $secondMediaAsset->id])
            ->assertJsonFragment(['url' => 'https://cdn.example.test/uploads/second.jpg']);

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $firstMediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $secondMediaAsset->id,
        ]);
        $this->assertDatabaseCount('card_media', 2);
    }

    public function test_attach_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAssets = MediaAsset::factory()->count(3)->for($user)->create();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);
        $otherMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        $this->withMediaRateLimitOverride(
            CardMediaRateLimiter::ATTACH_NAME,
            [$user->id, $otherUser->id],
            function () use ($card, $mediaAssets, $otherCard, $otherMediaAsset, $otherUser, $user): void {
                foreach ($mediaAssets->take(2) as $mediaAsset) {
                    $this
                        ->postJson("/api/cards/{$card->id}/media-assets", ['media_asset_id' => $mediaAsset->id])
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson("/api/cards/{$otherCard->id}/media-assets", ['media_asset_id' => $otherMediaAsset->id])
                    ->assertOk();

                $this->signIn($user);

                $blockedMediaAsset = $mediaAssets->last();

                $this
                    ->postJson("/api/cards/{$card->id}/media-assets", ['media_asset_id' => $blockedMediaAsset->id])
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$card->id}/media-assets")
                    ->assertOk()
                    ->assertJsonCount(2, 'data');

                $this->assertDatabaseHas('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $mediaAssets[0]->id,
                ]);
                $this->assertDatabaseHas('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $mediaAssets[1]->id,
                ]);
                $this->assertDatabaseHas('card_media', [
                    'card_id' => $otherCard->id,
                    'media_asset_id' => $otherMediaAsset->id,
                ]);
                $this->assertDatabaseMissing('card_media', [
                    'card_id' => $card->id,
                    'media_asset_id' => $blockedMediaAsset->id,
                ]);
                $this->assertDatabaseMissing('sync_feed_entries', [
                    'resource_id' => CardMediaSyncPayload::resourceId($card->id, $blockedMediaAsset->id),
                ]);
            },
        );
    }

    public function test_it_returns_validation_error_when_media_asset_is_deleted_after_validation(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                $data->mediaAsset->delete();

                throw new QueryException(
                    connectionName: 'sqlite',
                    sql: 'insert into card_media',
                    bindings: [],
                    previous: new Exception('SQLSTATE[23000]: Integrity constraint violation', 23000),
                );
            }
        });

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_asset_id']);

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_treats_concurrent_duplicate_attach_as_idempotent_success(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                throw new QueryException(
                    connectionName: 'sqlite',
                    sql: 'insert into card_media',
                    bindings: [],
                    previous: new Exception('SQLSTATE[23000]: Integrity constraint violation', 23000),
                );
            }
        });

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonCount(1, 'data.media_assets')
            ->assertJsonPath('data.media_assets.0.id', $mediaAsset->id);

        $this->assertDatabaseCount('card_media', 1);
    }

    public function test_it_returns_not_found_when_card_is_deleted_after_route_binding(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                $data->card->delete();

                throw new QueryException(
                    connectionName: 'sqlite',
                    sql: 'insert into card_media',
                    bindings: [],
                    previous: new Exception('SQLSTATE[23000]: Integrity constraint violation', 23000),
                );
            }
        });

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_returns_not_found_when_action_detects_owner_mismatch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                throw new MediaOwnershipException('Card and media asset must belong to the same user.');
            }
        });

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_reraises_non_integrity_database_errors(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                throw new QueryException(
                    connectionName: 'sqlite',
                    sql: 'insert into card_media',
                    bindings: [],
                    previous: new Exception('SQLSTATE[42000]: Syntax error or access violation', 42000),
                );
            }
        });

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertInternalServerError();

        $this->assertDatabaseCount('card_media', 0);
    }

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

    public function test_it_rejects_missing_card(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson('/api/cards/'.((string) Str::ulid()).'/media-assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response
            ->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_malformed_card_id(): void
    {
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson('/api/cards/not-a-ulid/media-assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_rejects_another_users_card(): void
    {
        $user = $this->signIn();
        $otherCard = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $response = $this->postJson("/api/cards/{$otherCard->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_hides_another_users_card_before_media_asset_validation(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->postJson("/api/cards/{$otherCard->id}/media-assets", [
            'media_asset_id' => 'not-a-ulid',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->postJson("/api/cards/{$card->id}/media-assets", [
            'media_asset_id' => $mediaAsset->id,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('card_media', 0);
    }
}
