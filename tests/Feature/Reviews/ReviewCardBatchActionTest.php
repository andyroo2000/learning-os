<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewCardBatchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_events_for_retried_client_events(): void
    {
        $firstCard = Card::factory()->create();
        $secondCard = Card::factory()->create();

        $items = [
            ReviewCardData::fromInput(
                cardId: $firstCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00Z',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:14:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $secondCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-05-27T09:20:00Z',
                clientEventId: 'event-456',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27T09:19:00Z',
            ),
        ];

        $firstResult = app(ReviewCardBatchAction::class)->handle($items);
        $secondResult = app(ReviewCardBatchAction::class)->handle($items);

        $this->assertSame($firstResult->pluck('id')->all(), $secondResult->pluck('id')->all());
        $this->assertSame(CardReviewRating::Good, $secondResult[0]->rating);
        $this->assertSame(CardReviewRating::Easy, $secondResult[1]->rating);
        $this->assertDatabaseCount('card_review_events', 2);
    }
}
