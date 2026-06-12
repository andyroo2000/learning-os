<?php

namespace Tests\Support\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Carbon;

trait AssertsCardMediaSyncFeedEntries
{
    protected function assertCardMediaSyncPayloadRecorded(
        int $userId,
        Card $card,
        MediaAsset $mediaAsset,
        SyncFeedOperation $operation,
        ?string $deckId,
        ?string $courseId,
        Carbon|string|null $createdAt,
        Carbon|string|null $updatedAt,
    ): SyncFeedEntry {
        $entries = SyncFeedEntry::query()
            ->where('domain', CardMediaSyncPayload::DOMAIN)
            ->where('resource_type', CardMediaSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', CardMediaSyncPayload::resourceId($card->id, $mediaAsset->id))
            ->where('operation', $operation->value)
            ->get();

        if ($entries->count() !== 1) {
            $this->fail("Expected exactly one card-media sync feed entry for {$card->id}:{$mediaAsset->id} with operation {$operation->value}; found {$entries->count()}.");
        }

        $entry = $entries->first();

        $this->assertSame($userId, $entry->user_id);
        $this->assertEquals(CardMediaSyncPayload::fromPivot(
            cardId: $card->id,
            mediaAssetId: $mediaAsset->id,
            deckId: $deckId,
            courseId: $courseId,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        ), $entry->payload);

        return $entry;
    }
}
