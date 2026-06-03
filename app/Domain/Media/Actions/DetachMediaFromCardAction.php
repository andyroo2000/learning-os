<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class DetachMediaFromCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(DetachMediaFromCardData $data): Card
    {
        DB::transaction(function () use ($data): void {
            $pivot = $this->pivotFor($data);
            $detachedCount = $data->card->mediaAssets()->detach($data->mediaAsset->id);

            if ($detachedCount < 1) {
                return;
            }

            $data->card->touch();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $data->card->ownerUserId(),
                    domain: CardMediaSyncPayload::DOMAIN,
                    resourceType: CardMediaSyncPayload::RESOURCE_TYPE,
                    resourceId: CardMediaSyncPayload::resourceId($data->card->id, $data->mediaAsset->id),
                    operation: SyncFeedOperation::Delete->value,
                    payload: CardMediaSyncPayload::fromPivot(
                        cardId: $data->card->id,
                        mediaAssetId: $data->mediaAsset->id,
                        createdAt: $pivot?->created_at,
                        updatedAt: $pivot?->updated_at,
                    ),
                ),
            );
        });

        return $data->card->load('mediaAssets');
    }

    private function pivotFor(DetachMediaFromCardData $data): ?object
    {
        return DB::table('card_media')
            ->where('card_id', $data->card->id)
            ->where('media_asset_id', $data->mediaAsset->id)
            ->first(['created_at', 'updated_at']);
    }
}
