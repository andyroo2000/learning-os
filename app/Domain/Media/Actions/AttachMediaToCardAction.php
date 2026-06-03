<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttachMediaToCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(AttachMediaToCardData $data): Card
    {
        DB::transaction(function () use ($data): void {
            $changes = $data->card->mediaAssets()->syncWithoutDetaching([$data->mediaAsset->id]);

            if ($changes['attached'] === []) {
                return;
            }

            $data->card->touch();
            $pivot = $this->pivotFor($data)
                ?? throw new RuntimeException('Card media pivot missing after attach.');

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $data->card->ownerUserId(),
                    domain: CardMediaSyncPayload::DOMAIN,
                    resourceType: CardMediaSyncPayload::RESOURCE_TYPE,
                    resourceId: CardMediaSyncPayload::resourceId($data->card->id, $data->mediaAsset->id),
                    operation: SyncFeedOperation::Create->value,
                    payload: CardMediaSyncPayload::fromPivot(
                        cardId: $data->card->id,
                        mediaAssetId: $data->mediaAsset->id,
                        createdAt: $pivot->created_at,
                        updatedAt: $pivot->updated_at,
                    ),
                ),
            );
        });

        return $data->card->load('mediaAssets');
    }

    private function pivotFor(AttachMediaToCardData $data): ?object
    {
        return DB::table('card_media')
            ->where('card_id', $data->card->id)
            ->where('media_asset_id', $data->mediaAsset->id)
            ->first(['created_at', 'updated_at']);
    }
}
