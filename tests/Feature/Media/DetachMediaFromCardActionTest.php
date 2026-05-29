<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetachMediaFromCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detaches_media_from_a_card(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $updatedCard = app(DetachMediaFromCardAction::class)->handle(
            DetachMediaFromCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertFalse($updatedCard->mediaAssets->contains($mediaAsset));

        $this->assertDatabaseMissing('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertTrue($card->fresh()->updated_at->isAfter($timestamp));
    }

    public function test_it_is_idempotent_when_attachment_is_already_missing(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $mediaAsset = MediaAsset::factory()->create();

        $updatedCard = app(DetachMediaFromCardAction::class)->handle(
            DetachMediaFromCardData::fromModels($card, $mediaAsset),
        );

        $this->assertTrue($updatedCard->is($card));
        $this->assertCount(0, $updatedCard->mediaAssets);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
    }
}
