<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
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

    public function test_card_media_attachment_is_deleted_when_card_is_deleted(): void
    {
        $card = Card::factory()->create();
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $card->delete();

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
}
