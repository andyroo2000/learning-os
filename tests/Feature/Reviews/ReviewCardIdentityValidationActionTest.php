<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewCardIdentityValidationActionTest extends ReviewCardActionTestCase
{
    public function test_it_accepts_each_supported_rating(): void
    {
        $card = Card::factory()->create();
        $ids = [
            '01k1j8j9m0e4k7r2y8p5w6q3aa',
            '01k1j8j9m0e4k7r2y8p5w6q3ab',
            '01k1j8j9m0e4k7r2y8p5w6q3ac',
            '01k1j8j9m0e4k7r2y8p5w6q3ad',
        ];

        foreach (CardReviewRating::cases() as $index => $rating) {
            $result = $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: $rating->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    id: $ids[$index],
                ),
            );
            $reviewEvent = $result->reviewEvent;

            $this->assertTrue($result->wasCreated);
            $this->assertSame($rating, $reviewEvent->rating);
        }
    }

    public function test_it_rejects_invalid_card_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: 'not-a-ulid',
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
    }

    public function test_it_rejects_missing_card(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card does not exist.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: strtolower((string) Str::ulid()),
                rating: 'good',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
    }
}
