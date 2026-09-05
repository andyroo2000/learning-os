<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use InvalidArgumentException;

class ReviewCardValueValidationActionTest extends ReviewCardActionTestCase
{
    public function test_it_rejects_blank_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating is required.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: '   ',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
    }

    public function test_it_rejects_unsupported_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating must be one of: again, hard, good, easy.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'medium',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
    }

    public function test_it_rejects_invalid_duration_ms(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review duration_ms must be a non-negative integer.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            durationMs: '-1',
        );
    }
}
