<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Carbon;

class RecordCardMediaSyncFeedEntryAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(
        int $userId,
        SyncFeedOperation $operation,
        string $cardId,
        string $mediaAssetId,
        ?string $deckId,
        ?string $courseId,
        Carbon|string|null $createdAt,
        Carbon|string|null $updatedAt,
    ): SyncFeedEntry {
        return $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $userId,
                domain: CardMediaSyncPayload::DOMAIN,
                resourceType: CardMediaSyncPayload::RESOURCE_TYPE,
                resourceId: CardMediaSyncPayload::resourceId($cardId, $mediaAssetId),
                operation: $operation->value,
                payload: CardMediaSyncPayload::fromPivot(
                    cardId: $cardId,
                    mediaAssetId: $mediaAssetId,
                    deckId: $deckId,
                    courseId: $courseId,
                    createdAt: $createdAt,
                    updatedAt: $updatedAt,
                ),
            ),
        );
    }
}
