<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Support\CardMediaOwnership;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class DetachMediaFromCardAction
{
    public function __construct(
        private readonly RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
    ) {}

    public function handle(DetachMediaFromCardData $data): Card
    {
        // Ownership is immutable once set, so the pre-transaction check cannot race a reassignment.
        // Cross-owner retry cleanup is migration/admin-only because sync tombstones require shared ownership.
        // The ownership helper intentionally refreshes the card's deck relation cache with trashed decks.
        $ownerUserId = CardMediaOwnership::ownerUserIdFor($data->card, $data->mediaAsset);

        DB::transaction(function () use ($data, $ownerUserId): void {
            $pivot = $this->pivotFor($data);
            $detachedCount = $data->card->mediaAssets()->detach($data->mediaAsset->id);

            if ($detachedCount < 1) {
                return;
            }

            $data->card->touch();

            $this->recordCardMediaSyncFeedEntry->handle(
                userId: $ownerUserId,
                operation: SyncFeedOperation::Delete,
                cardId: $data->card->id,
                mediaAssetId: $data->mediaAsset->id,
                deckId: $data->card->deck_id,
                courseId: $data->card->deckCourseId(),
                createdAt: $pivot?->created_at,
                updatedAt: $pivot?->updated_at,
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
