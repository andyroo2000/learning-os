<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewCardProvidedIdActionTest extends ReviewCardActionTestCase
{
    public function test_card_millisecond_chronology_rejects_an_older_within_second_review_without_an_event_tiebreaker(): void
    {
        $card = Card::factory()->create();
        DB::table('cards')->where('id', $card->id)->update([
            'last_reviewed_at' => '2026-05-27 09:15:00.456',
        ]);

        try {
            $this->reviewCard(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-05-27T09:15:00.123999Z',
            ));

            $this->fail('Expected within-second chronology conflict was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_review_event_out_of_order', $exception->reason());
        }

        $this->assertSame('2026-05-27T09:15:00.456000Z', $card->refresh()->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());

        $result = $this->reviewCard(
            ReviewCardData::fromInput(
                cardId: $card->id,
                rating: 'easy',
                reviewedAt: '2026-05-27T09:15:00Z',
                id: $id,
            ),
        );
        $reviewEvent = $result->reviewEvent;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($id, $reviewEvent->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
        $entry = SyncFeedEntry::query()
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $id)
            ->where('operation', SyncFeedOperation::Create->value)
            ->sole();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame(CardReviewEventSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CardReviewEventSyncPayload::fromReviewEvent($result->reviewEvent), $entry->payload);
    }
}
