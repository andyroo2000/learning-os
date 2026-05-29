<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachMediaToCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_media_to_a_card(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertTrue($updatedCard->mediaAssets->contains($mediaAsset));

        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertTrue($card->fresh()->updated_at->isAfter($timestamp));
    }

    public function test_it_is_idempotent_for_existing_attachments(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $mediaAsset),
        );

        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_loads_media_assets_in_id_order(): void
    {
        $card = Card::factory()->create();
        $firstMediaAsset = MediaAsset::factory()->create();
        $secondMediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($secondMediaAsset->id);

        $updatedCard = app(AttachMediaToCardAction::class)->handle(
            AttachMediaToCardData::fromModels($card, $firstMediaAsset),
        );
        $expectedMediaAssetIds = collect([$firstMediaAsset->id, $secondMediaAsset->id])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedMediaAssetIds, $updatedCard->mediaAssets->pluck('id')->all());
    }
}
