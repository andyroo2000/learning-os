<?php

namespace Tests\Support\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Reviews\Actions\AdvanceCardProgressionAfterReviewAction;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

trait BuildsCardProgressionFixtures
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite models a fixed review chronology on August 25, 2026.
        Carbon::setTestNow('2026-08-25T12:00:00Z');
    }

    /** @return array{User, Deck} */
    private function learnerDeck(): array
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create(['user_id' => $user->id]);

        return [$user, $deck];
    }

    /** @param array<string, mixed> $attributes */
    private function familyCard(
        Deck $deck,
        string $groupId,
        CardProgressionFixtureStage $stage,
        array $attributes = [],
    ): Card {
        return Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => $stage->number,
            'variant_status' => $stage->status->value,
            'variant_unlocked_at' => $stage->status === VocabVariantStatus::Available ? now()->subDay() : null,
            'new_queue_position' => null,
            ...$attributes,
        ]);
    }

    private function review(Card $card, CardReviewRating $rating, string $reviewedAt): void
    {
        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: $rating->value,
            reviewedAt: $reviewedAt,
        ));
    }

    private function advance(Card $card, CardReviewRating $rating = CardReviewRating::Good): void
    {
        $reviewEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => $rating->value,
            'reviewed_at' => now(),
        ]);

        DB::transaction(function () use ($card, $reviewEvent): void {
            app(NewCardQueuePosition::class)->lockOwner($card->ownerUserId());
            $lockedCard = Card::query()
                ->with('deck')
                ->whereKey($card->id)
                ->lockForUpdate()
                ->firstOrFail();

            app(AdvanceCardProgressionAfterReviewAction::class)->handle(
                $lockedCard,
                $reviewEvent->reviewed_at,
                $reviewEvent->id,
            );
        });
    }
}
