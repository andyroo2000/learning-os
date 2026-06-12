<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Support\CardMediaOwnership;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttachMediaToCardAction
{
    public function __construct(
        private readonly RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
    ) {}

    public function handle(AttachMediaToCardData $data): Card
    {
        // Ownership is immutable once set, so the pre-transaction check cannot race a reassignment.
        // Keep this outside the transaction to avoid extra reads while holding the write lock.
        // The ownership helper intentionally refreshes the card's deck relation cache with trashed decks.
        $ownerUserId = CardMediaOwnership::ownerUserIdFor($data->card, $data->mediaAsset);

        DB::transaction(function () use ($data, $ownerUserId): void {
            $changes = $data->card->mediaAssets()->syncWithoutDetaching([$data->mediaAsset->id]);

            if ($changes['attached'] === []) {
                return;
            }

            $data->card->touch();
            $pivot = $this->pivotFor($data)
                ?? throw new RuntimeException('Card media pivot missing after attach.');

            $this->recordCardMediaSyncFeedEntry->handle(
                userId: $ownerUserId,
                operation: SyncFeedOperation::Create,
                cardId: $data->card->id,
                mediaAssetId: $data->mediaAsset->id,
                deckId: $data->card->deck_id,
                courseId: $data->card->deckCourseId(),
                createdAt: $pivot->created_at,
                updatedAt: $pivot->updated_at,
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
