<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Results\ReviewCardResult;
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

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: $reviewedAt,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->created);
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

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27 09:15:00',
                id: $id,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->created);
        $this->assertSame($id, $reviewEvent->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_stores_client_sync_metadata(): void
    {
        $card = Card::factory()->create();
        $clientCreatedAt = Carbon::parse('2026-05-27 09:14:00');

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: $clientCreatedAt,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->created);
        $this->assertSame('event-123', $reviewEvent->client_event_id);
        $this->assertSame('device-abc', $reviewEvent->device_id);
        $this->assertTrue($clientCreatedAt->equalTo($reviewEvent->client_created_at));

        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_is_idempotent_for_the_same_client_event_and_device(): void
    {
        $card = Card::factory()->create();

        $firstResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27 09:14:00',
            ),
        );

        $secondResult = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27 09:20:00',
                clientEventId: 'event-123',
                deviceId: 'device-abc',
                clientCreatedAt: '2026-05-27 09:19:00',
            ),
        );
        $firstReviewEvent = $firstResult->reviewEvent;
        $secondReviewEvent = $secondResult->reviewEvent;

        $this->assertTrue($firstResult->created);
        $this->assertFalse($secondResult->created);
        $this->assertTrue($firstReviewEvent->is($secondReviewEvent));
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $firstReviewEvent->id,
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = Card::factory()->create();

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: "  {$card->id}  ",
                rating: '  hard  ',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->created);
        $this->assertSame($card->id, $reviewEvent->card_id);
        $this->assertSame(CardReviewRating::Hard, $reviewEvent->rating);
    }

    public function test_it_accepts_each_supported_rating(): void
    {
        $card = Card::factory()->create();

        foreach (CardReviewRating::cases() as $rating) {
            $result = $this->reviewCard(
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: $rating->value,
                    reviewedAt: '2026-05-27 09:15:00',
                ),
            );
            $reviewEvent = $result->reviewEvent;

            $this->assertTrue($result->created);
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
                reviewedAt: '2026-05-27 09:15:00',
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
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_blank_rating(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review rating is required.');

        $this->reviewCard(
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

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'medium',
                reviewedAt: '2026-05-27 09:15:00',
            ),
        );
    }

    public function test_it_rejects_partial_client_sync_metadata(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client event ID, device ID, and client created at must be provided together.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                clientEventId: 'event-123',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $card = Card::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Review event ID must be a valid ULID.');

        $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'good',
                reviewedAt: '2026-05-27 09:15:00',
                id: 'not-a-ulid',
            ),
        );
    }

    private function reviewCard(ReviewCardData $data): ReviewCardResult
    {
        return app(ReviewCardAction::class)->handle($data);
    }
}
