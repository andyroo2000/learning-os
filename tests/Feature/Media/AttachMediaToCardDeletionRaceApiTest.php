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

class AttachMediaToCardDeletionRaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_not_found_when_card_is_deleted_after_route_binding(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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

    public function test_it_does_not_recover_a_duplicate_attach_after_the_card_is_deleted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_does_not_recover_a_duplicate_attach_after_the_card_deck_is_deleted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
        {
            public function handle(AttachMediaToCardData $data): Card
            {
                $data->card->deck->delete();

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
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
