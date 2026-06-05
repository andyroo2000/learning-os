<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\ListStudyExportCardMediaAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportCardMediaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_current_card_media_pairs_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $otherUser = User::factory()->create();
        $firstCard = Card::factory()->for($deck)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh31',
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh32',
        ]);
        $deletedCard = Card::factory()->for($deck)->create();
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherUserCard = $this->cardFor($otherUser);
        $earlierMedia = MediaAsset::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh34',
        ]);
        $laterMedia = MediaAsset::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ]);
        $crossUserMedia = MediaAsset::factory()->for($otherUser)->create();
        $deletedMedia = MediaAsset::factory()->for($user)->create();

        $secondCard->mediaAssets()->attach($laterMedia->id);
        $firstCard->mediaAssets()->attach($laterMedia->id);
        $firstCard->mediaAssets()->attach($earlierMedia->id);
        $firstCard->mediaAssets()->attach($crossUserMedia->id);
        $firstCard->mediaAssets()->attach($deletedMedia->id);
        $deletedCard->mediaAssets()->attach($laterMedia->id);
        $cardInDeletedDeck->mediaAssets()->attach($laterMedia->id);
        $otherUserCard->mediaAssets()->attach($laterMedia->id);

        $deletedMedia->delete();
        $deletedCard->delete();
        $deletedDeck->delete();

        $pairs = app(ListStudyExportCardMediaAction::class)->handle($user->id);

        $this->assertSame([
            [$firstCard->id, $earlierMedia->id],
            [$firstCard->id, $laterMedia->id],
            [$secondCard->id, $laterMedia->id],
        ], $pairs->map(fn (object $pair): array => [
            $pair->card_id,
            $pair->media_asset_id,
        ])->all());
    }
}
