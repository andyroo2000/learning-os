<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use InvalidArgumentException;

class ReviewCardInputNormalizationActionTest extends ReviewCardActionTestCase
{
    public function test_it_trims_text_inputs(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: "  {$card->id}  ",
                rating: '  hard  ',
                reviewedAt: '2026-05-27T09:15:00Z',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Hard, $reviewEvent->rating);
    }

    public function test_it_rejects_timezone_naive_timestamp_strings_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reviewed_at must be a valid ISO-8601 datetime.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00',
        );
    }

    public function test_it_rejects_timezone_naive_client_created_timestamp_strings_for_direct_callers(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_created_at must be a valid ISO-8601 datetime.');

        ReviewCardData::fromInput(
            cardId: $card->id,
            rating: 'good',
            reviewedAt: '2026-05-27T09:15:00Z',
            clientEventId: 'event-123',
            deviceId: 'device-abc',
            clientCreatedAt: '2026-05-27T09:14:00',
        );
    }
}
