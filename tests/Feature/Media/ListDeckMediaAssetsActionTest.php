<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\ListDeckMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDeckMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_unique_user_owned_media_attached_to_cards_in_a_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $mediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/example.jpg')
            ->create([
                'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'example.jpg',
            ]);
        $laterMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pb',
        ]);
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();
        $otherDeck = $this->deckFor($user);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $firstCard->mediaAssets()->attach($mediaAsset->id);
        $secondCard->mediaAssets()->attach($mediaAsset->id);
        $firstCard->mediaAssets()->attach($laterMediaAsset->id);
        $firstCard->mediaAssets()->attach($crossUserMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);

        $mediaAssets = app(ListDeckMediaAssetsAction::class)->handle($deck);

        $this->assertSame([$mediaAsset->id, $laterMediaAsset->id], $mediaAssets->pluck('id')->all());
        $this->assertNotContains($crossUserMediaAsset->id, $mediaAssets->pluck('id')->all());
        $this->assertNotContains($otherDeckMediaAsset->id, $mediaAssets->pluck('id')->all());

        $firstMediaAsset = $mediaAssets->first();

        $this->assertSame('https://cdn.example.test/uploads/example.jpg', $firstMediaAsset->public_url);
        $this->assertSame('image/jpeg', $firstMediaAsset->mime_type);
        $this->assertSame(123_456, $firstMediaAsset->size_bytes);
        $this->assertSame(str_repeat('a', 64), $firstMediaAsset->checksum_sha256);
        $this->assertSame('example.jpg', $firstMediaAsset->original_filename);
        $this->assertNotNull($firstMediaAsset->created_at);
        $this->assertNotNull($firstMediaAsset->updated_at);
        $this->assertEqualsCanonicalizing(
            [
                'id',
                'public_url',
                'mime_type',
                'size_bytes',
                'checksum_sha256',
                'original_filename',
                'created_at',
                'updated_at',
            ],
            array_keys($firstMediaAsset->getAttributes()),
        );
    }
}
