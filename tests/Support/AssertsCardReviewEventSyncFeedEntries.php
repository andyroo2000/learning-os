<?php

namespace Tests\Support;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

trait AssertsCardReviewEventSyncFeedEntries
{
    protected function assertCardReviewEventDeleteSyncPayloadRecorded(CardReviewEvent $reviewEvent): SyncFeedEntry
    {
        $entry = $this->syncFeedEntryForReviewEvent($reviewEvent, SyncFeedOperation::Delete);
        $deletedAt = $entry->payload['deleted_at'] ?? null;

        $this->assertIsString($deletedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $deletedAt);

        $this->assertCardReviewEventSyncEntryMatchesPayload(
            $entry,
            $reviewEvent,
            SyncFeedOperation::Delete,
            Carbon::parse($deletedAt),
        );

        return $entry;
    }

    protected function assertCardReviewEventSyncPayloadRecorded(
        CardReviewEvent $reviewEvent,
        SyncFeedOperation $operation,
    ): SyncFeedEntry {
        if ($operation === SyncFeedOperation::Delete) {
            $this->fail('Use assertCardReviewEventDeleteSyncPayloadRecorded() for review-event delete sync payloads.');
        }

        $entry = $this->syncFeedEntryForReviewEvent($reviewEvent, $operation);

        $this->assertCardReviewEventSyncEntryMatchesPayload($entry, $reviewEvent, $operation);

        return $entry;
    }

    private function assertCardReviewEventSyncEntryMatchesPayload(
        SyncFeedEntry $entry,
        CardReviewEvent $reviewEvent,
        SyncFeedOperation $operation,
        ?CarbonInterface $deletedAt = null,
    ): void {
        $card = $reviewEvent->relationLoaded('card')
            ? $reviewEvent->card
            : $reviewEvent->card()
                ->withTrashed()
                ->with(['deck' => fn (Builder $query) => $query->withTrashed()])
                ->first();

        $this->assertNotNull($card, "Expected review event {$reviewEvent->id} to have a card for sync ownership assertions.");

        if (! $card->relationLoaded('deck')) {
            $card->load(['deck' => fn (Builder $query) => $query->withTrashed()]);
        }

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertSame($operation, $entry->operation);
        $this->assertEquals(CardReviewEventSyncPayload::fromReviewEvent($reviewEvent, $deletedAt), $entry->payload);
    }

    private function syncFeedEntryForReviewEvent(CardReviewEvent $reviewEvent, SyncFeedOperation $operation): SyncFeedEntry
    {
        $entries = SyncFeedEntry::query()
            ->where('domain', CardReviewEventSyncPayload::DOMAIN)
            ->where('resource_type', CardReviewEventSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $reviewEvent->id)
            ->where('operation', $operation->value)
            ->get();

        $this->assertCount(
            1,
            $entries,
            "Expected exactly one card_review_event sync feed entry for review event {$reviewEvent->id} with operation {$operation->value}.",
        );

        return $entries->first();
    }
}
