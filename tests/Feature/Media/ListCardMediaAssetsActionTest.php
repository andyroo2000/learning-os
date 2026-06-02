<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\ListCardMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsMediaAssetManifests;
use Tests\TestCase;

class ListCardMediaAssetsActionTest extends TestCase
{
    use AssertsMediaAssetManifests, RefreshDatabase;

    public function test_it_lists_media_attached_to_a_card_in_id_order(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $otherCard = Card::factory()->create();
        // These fixed ULIDs are ordered lexicographically so the assertion proves the action's ID ordering.
        $earlierMediaAsset = MediaAsset::factory()
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);
        $laterMediaAsset = MediaAsset::factory()->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pb',
        ]);
        $otherCardMediaAsset = MediaAsset::factory()->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pc',
        ]);

        $card->mediaAssets()->attach($laterMediaAsset->id);
        $card->mediaAssets()->attach($earlierMediaAsset->id);
        $otherCard->mediaAssets()->attach($otherCardMediaAsset->id);

        $mediaAssets = app(ListCardMediaAssetsAction::class)->handle($card);

        $this->assertSame(
            [$earlierMediaAsset->id, $laterMediaAsset->id],
            $mediaAssets->pluck('id')->all(),
        );
        $this->assertNotContains($otherCardMediaAsset->id, $mediaAssets->pluck('id')->all());

        $firstMediaAsset = $mediaAssets->first();

        $this->assertSame('https://cdn.example.test/uploads/example.jpg', $firstMediaAsset->public_url);
        $this->assertSame('image/jpeg', $firstMediaAsset->mime_type);
        $this->assertSame(123_456, $firstMediaAsset->size_bytes);
        $this->assertSame(str_repeat('a', 64), $firstMediaAsset->checksum_sha256);
        $this->assertSame('example.jpg', $firstMediaAsset->original_filename);
        $this->assertNotNull($firstMediaAsset->created_at);
        $this->assertNotNull($firstMediaAsset->updated_at);
        $this->assertMediaAssetManifestAttributes($firstMediaAsset);
    }

    public function test_it_returns_an_empty_manifest_for_a_card_without_media(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $mediaAssets = app(ListCardMediaAssetsAction::class)->handle($card);

        $this->assertEmpty($mediaAssets);
    }
}
