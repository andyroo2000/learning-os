<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Support\Str;

class ReviewCardPrecisionActionTest extends ReviewCardActionTestCase
{
    public function test_direct_callers_replay_sub_millisecond_review_instants_at_database_precision(): void
    {
        $card = Card::factory()->create();
        $id = strtolower((string) Str::ulid());
        $firstData = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T05:15:00.123111-04:00',
            id: $id,
        );
        $replayData = ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.123999Z',
            id: $id,
        );

        $first = $this->reviewCard($firstData);
        $second = $this->reviewCard($replayData);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($id, $second->reviewEvent->id);
        $this->assertSame('2026-05-27T09:15:00.123000Z', $second->reviewEvent->reviewed_at->toJSON());
        $card->refresh()->load('deck');

        $this->assertSame('2026-05-27T09:15:00.123000Z', $card->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T09:15:00.123000Z', CardResource::make($card)->resolve()['last_reviewed_at']);
        $this->assertSame(1, $card->scheduler_state['reps']);
        $this->assertDatabaseCount('card_review_events', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);

        $cardEntry = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->sole();

        $this->assertSame('2026-05-27T09:15:00.123000Z', $cardEntry->payload['last_reviewed_at']);
    }

    public function test_within_second_review_chronology_reaches_snapshots_resources_and_sync(): void
    {
        $card = Card::factory()->create();

        $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.123999Z',
        ));
        $later = $this->reviewCard(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: CardReviewRating::Good->value,
            reviewedAt: '2026-05-27T09:15:00.456999Z',
        ));

        $card->refresh()->load('deck');
        $this->assertSame('2026-05-27T09:15:00.123000Z', $later->reviewEvent->card_state_before['last_reviewed_at']);
        $this->assertSame('2026-05-27T09:15:00.456000Z', $card->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T09:15:00.456000Z', CardResource::make($card)->resolve()['last_reviewed_at']);

        $cardEntries = SyncFeedEntry::query()
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->orderBy('checkpoint')
            ->get();

        $this->assertCount(2, $cardEntries);
        $this->assertSame('2026-05-27T09:15:00.123000Z', $cardEntries[0]->payload['last_reviewed_at']);
        $this->assertSame('2026-05-27T09:15:00.456000Z', $cardEntries[1]->payload['last_reviewed_at']);
        $this->assertDatabaseCount('card_review_events', 2);
        $this->assertDatabaseCount('sync_feed_entries', 4);
    }
}
