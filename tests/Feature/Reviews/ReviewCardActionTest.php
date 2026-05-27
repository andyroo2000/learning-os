<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ReviewCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_card_review_event(): void
    {
        $card = Card::factory()->create();
        $reviewedAt = Carbon::parse('2026-05-27 09:15:00');

        $reviewEvent = app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
            ),
        );

        $this->assertTrue(Str::isUlid($reviewEvent->id));
        $this->assertSame(CardReviewRating::Good, $reviewEvent->rating);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt,
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $reviewEvent = app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27 09:15:00',
                id: $id,
            ),
        );

        $this->assertSame($id, $reviewEvent->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = Card::factory()->create();

        $reviewEvent = app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: "  {$card->id}  ",
                rating: '  hard  ',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );

        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Hard, $reviewEvent->rating);
    }

    public function test_it_accepts_each_supported_rating(): void
    {
        $card = Card::factory()->create();

        foreach (CardReviewRating::cases() as $rating) {
            $reviewEvent = app(ReviewCardAction::class)->handle(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: $rating->value,
                    reviewedAt: '2026-05-27 09:15:00',
                ),
            );

            $this->assertSame($rating, $reviewEvent->rating);
        }
    }

    public function test_it_rejects_invalid_card_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: 'not-a-ulid',
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_missing_card(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card does not exist.');

        app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: strtolower((string) Str::ulid()),
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_blank_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating is required.');

        app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: '   ',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_unsupported_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating must be one of: again, hard, good, easy.');

        app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'medium',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event ID must be a valid ULID.');

        app(ReviewCardAction::class)->handle(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                id: 'not-a-ulid',
            ),
        );
    }
}
