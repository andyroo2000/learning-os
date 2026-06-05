<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListStudyExportCardMediaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/card-media')->assertUnauthorized();
    }

    public function test_index_returns_card_media_pairs_for_the_authenticated_user(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $otherUser = User::factory()->create();
        $card = Card::factory()->for($deck)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh31',
        ]);
        $deletedCard = Card::factory()->for($deck)->create();
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherUserCard = $this->cardFor($otherUser);
        $mediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh32',
        ]);
        $crossUserMediaAsset = MediaAsset::factory()->for($otherUser)->create();
        $deletedMediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($mediaAsset->id);
        $card->mediaAssets()->attach($crossUserMediaAsset->id);
        $card->mediaAssets()->attach($deletedMediaAsset->id);
        $deletedCard->mediaAssets()->attach($mediaAsset->id);
        $cardInDeletedDeck->mediaAssets()->attach($mediaAsset->id);
        $otherUserCard->mediaAssets()->attach($mediaAsset->id);

        $deletedMediaAsset->delete();
        $deletedCard->delete();
        $deletedDeck->delete();

        $this->getJson('/api/study/export/card-media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_id', $card->id)
            ->assertJsonPath('data.0.media_asset_id', $mediaAsset->id)
            ->assertJsonPath('data.0.created_at', '2026-06-05T12:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-06-05T12:00:00.000000Z')
            ->assertJsonMissing([
                'media_asset_id' => $crossUserMediaAsset->id,
            ])
            ->assertJsonMissing([
                'media_asset_id' => $deletedMediaAsset->id,
            ])
            ->assertJsonMissing([
                'card_id' => $deletedCard->id,
            ])
            ->assertJsonMissing([
                'card_id' => $cardInDeletedDeck->id,
            ])
            ->assertJsonMissing([
                'card_id' => $otherUserCard->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'card_id',
                        'media_asset_id',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }
}
