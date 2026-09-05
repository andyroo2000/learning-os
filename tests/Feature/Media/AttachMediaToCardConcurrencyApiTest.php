<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardConcurrencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_validation_error_when_media_asset_is_deleted_after_validation(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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
        $this->assertNotNull($card->updated_at);
        $originalUpdatedAt = $card->updated_at->toJSON();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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

        $card->refresh();

        $this->assertNotNull($card->updated_at);
        $this->assertSame($originalUpdatedAt, $card->updated_at->toJSON());
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
