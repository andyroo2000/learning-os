<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CardMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_media_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('card_media', [
            'card_id',
            'media_asset_id',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_card_media_table_has_one_unique_card_media_pair_index(): void
    {
        $matchingIndexes = collect(Schema::getIndexes('card_media'))
            ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true)
            ->filter(fn (array $index): bool => ($index['columns'] ?? []) === ['card_id', 'media_asset_id']);

        $this->assertCount(1, $matchingIndexes);
    }

    public function test_media_asset_can_be_attached_to_a_card(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $this->assertTrue($card->mediaAssets->contains($mediaAsset));
        $this->assertTrue($mediaAsset->cards->contains($card));

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_card_media_attachment_pairs_are_unique(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $this->expectException(QueryException::class);

        $card->mediaAssets()->attach($mediaAsset->id);
    }

    public function test_card_media_attachment_is_kept_when_card_is_soft_deleted(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $card->delete();

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
        ]);
    }

    public function test_card_media_attachment_is_deleted_when_card_is_force_deleted(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $card->forceDelete();

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
        ]);
    }

    public function test_card_media_attachment_is_deleted_when_media_asset_is_deleted(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $mediaAsset->delete();

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_cleanup_migration_removes_cross_owner_card_media_attachments(): void
    {
        $owner = $this->signIn();
        $otherUser = User::factory()->create();
        $sameOwnerCard = $this->cardFor($owner);
        $sameOwnerMediaAsset = $this->mediaAssetFor($owner);
        $crossOwnerCard = $this->cardFor($owner);
        $crossOwnerMediaAsset = $this->mediaAssetFor($otherUser);

        $sameOwnerCard->mediaAssets()->attach($sameOwnerMediaAsset->id);
        $crossOwnerCard->mediaAssets()->attach($crossOwnerMediaAsset->id);

        $this->runCardMediaCleanupMigration();

        $this->assertDatabaseHas('card_media', [
            'card_id' => $sameOwnerCard->id,
            'media_asset_id' => $sameOwnerMediaAsset->id,
        ]);
        $this->assertDatabaseMissing('card_media', [
            'card_id' => $crossOwnerCard->id,
            'media_asset_id' => $crossOwnerMediaAsset->id,
        ]);
    }

    public function test_cleanup_migration_removes_cross_owner_card_media_attachments_for_soft_deleted_records(): void
    {
        $owner = $this->signIn();
        $otherUser = User::factory()->create();
        $softDeletedCard = $this->cardFor($owner);
        $softDeletedCardMediaAsset = $this->mediaAssetFor($otherUser);
        $softDeletedDeckCard = $this->cardFor($owner);
        $softDeletedDeckMediaAsset = $this->mediaAssetFor($otherUser);

        $softDeletedCard->mediaAssets()->attach($softDeletedCardMediaAsset->id);
        $softDeletedDeckCard->mediaAssets()->attach($softDeletedDeckMediaAsset->id);

        $softDeletedCard->delete();
        $softDeletedDeckCard->deck()->firstOrFail()->delete();

        $this->runCardMediaCleanupMigration();

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $softDeletedCard->id,
            'media_asset_id' => $softDeletedCardMediaAsset->id,
        ]);
        $this->assertDatabaseMissing('card_media', [
            'card_id' => $softDeletedDeckCard->id,
            'media_asset_id' => $softDeletedDeckMediaAsset->id,
        ]);
    }

    private function runCardMediaCleanupMigration(): void
    {
        $migrationFiles = glob(database_path('migrations/*_prune_cross_owner_card_media_pivots.php'));

        $this->assertIsArray($migrationFiles);
        $this->assertCount(1, $migrationFiles, 'Cleanup migration not found.');

        $migration = include $migrationFiles[0];
        $migration->up();
    }
}
