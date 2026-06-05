<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ListStudyExportReviewEventsAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListStudyExportReviewEventsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_current_review_events_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $deletedCard = $this->cardFor($user);
        $deletedDeck = $this->deckFor($user);
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherCard = $this->cardFor(User::factory()->create());

        $firstExportedEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Hard,
            'reviewed_at' => now(),
        ]);
        $secondExportedEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now()->subDay(),
        ]);
        $deletedCardEvent = CardReviewEvent::factory()->for($deletedCard)->create();
        $deletedDeckEvent = CardReviewEvent::factory()->for($cardInDeletedDeck)->create();

        CardReviewEvent::factory()->for($otherCard)->create();
        $deletedCard->delete();
        DB::table('decks')
            ->where('id', $deletedDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $events = app(ListStudyExportReviewEventsAction::class)->handle($user->id);

        $this->assertSame(
            [$firstExportedEvent->id, $secondExportedEvent->id],
            $events->pluck('id')->all(),
        );
        $this->assertSame(
            [CardReviewRating::Hard, CardReviewRating::Good],
            $events->pluck('rating')->all(),
        );
        $this->assertFalse($events->contains('id', $deletedCardEvent->id));
        $this->assertFalse($events->contains('id', $deletedDeckEvent->id));
    }
}
