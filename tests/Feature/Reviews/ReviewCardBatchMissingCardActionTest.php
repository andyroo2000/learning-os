<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewCardBatchMissingCardActionTest extends ReviewCardBatchActionTestCase
{
    public function test_it_rejects_missing_cards_without_creating_review_events(): void
    {
        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: strtolower((string) Str::ulid()),
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-05-27T09:15:00Z',
                    clientEventId: 'event-123',
                    deviceId: 'device-abc',
                    clientCreatedAt: '2026-05-27T09:14:00Z',
                ),
            ]);

            $this->fail('Expected missing card validation to reject the batch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('One or more cards do not exist.', $exception->getMessage());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
