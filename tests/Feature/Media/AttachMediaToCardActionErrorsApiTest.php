<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardActionErrorsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_not_found_when_action_detects_owner_mismatch(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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

        $this->app->instance(AttachMediaToCardAction::class, new class(app(RecordCardMediaSyncFeedEntryAction::class)) extends AttachMediaToCardAction
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
}
