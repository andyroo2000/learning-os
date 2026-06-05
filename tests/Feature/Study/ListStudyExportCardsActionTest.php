<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\ListStudyExportCardsAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListStudyExportCardsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_current_cards_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());

        $firstExportedCard = Card::factory()->for($deck)->create([
            'front_text' => 'Second card',
            'back_text' => 'second',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::Review,
            'created_at' => now(),
        ]);
        $secondExportedCard = Card::factory()->for($deck)->create([
            'front_text' => 'First card',
            'back_text' => 'first',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::New,
            'created_at' => now()->subDay(),
        ]);
        $deletedCard = Card::factory()->for($deck)->create([
            'front_text' => 'Deleted card',
        ]);
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create([
            'front_text' => 'Hidden by deck tombstone',
        ]);

        Card::factory()->for($otherDeck)->create([
            'front_text' => 'Hidden card',
        ]);
        $deletedCard->delete();
        DB::table('decks')
            ->where('id', $deletedDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $cards = app(ListStudyExportCardsAction::class)->handle($user->id);

        $this->assertSame(
            [$firstExportedCard->id, $secondExportedCard->id],
            $cards->pluck('id')->all(),
        );
        $this->assertSame(
            [CardType::Production, CardType::Recognition],
            $cards->pluck('card_type')->all(),
        );
        $this->assertSame(
            [CardStudyStatus::Review, CardStudyStatus::New],
            $cards->pluck('study_status')->all(),
        );
    }
}
