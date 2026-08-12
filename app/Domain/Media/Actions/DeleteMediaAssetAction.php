<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeleteMediaAssetAction
{
    public function __construct(
        private readonly RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        private readonly RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
    ) {}

    public function handle(DeleteMediaAssetData $data): void
    {
        DB::transaction(function () use ($data): void {
            // Serialize the pivot snapshot with concurrent FK-backed attachments. Without
            // this parent-row lock, an attachment can commit after the snapshot but before
            // the hard delete and be cascade-deleted without a sync tombstone.
            // Scoping by user keeps missing, already-deleted, and cross-user assets as the
            // same no-op outcome for offline retry safety and to avoid asset enumeration.
            $mediaAsset = MediaAsset::query()
                ->whereKey($data->mediaAssetId)
                ->where('user_id', $data->userId)
                ->lockForUpdate()
                ->first();

            if ($mediaAsset === null) {
                return;
            }

            // Load the model before deleting so future Eloquent events can coordinate storage cleanup.
            // Register storage cleanup on a MediaAsset deleted observer once physical uploads exist.
            // MediaAsset is hard-deleted, so card_media cleanup can rely on ON DELETE CASCADE.
            $cardMediaPivots = $this->ownedCardMediaPivotsFor($mediaAsset);

            $mediaAsset->delete();

            foreach ($cardMediaPivots as $pivot) {
                $this->recordCardMediaSyncFeedEntry->handle(
                    userId: $mediaAsset->user_id,
                    operation: SyncFeedOperation::Delete,
                    cardId: $pivot->card_id,
                    mediaAssetId: $mediaAsset->id,
                    deckId: $pivot->deck_id,
                    courseId: $pivot->course_id,
                    createdAt: $pivot->created_at,
                    updatedAt: $pivot->updated_at,
                );
            }

            $this->recordMediaAssetSyncFeedEntry->handle(
                userId: $mediaAsset->user_id,
                operation: SyncFeedOperation::Delete,
                mediaAsset: $mediaAsset,
            );
        });
    }

    /**
     * @return Collection<int, object{card_id: string, deck_id: string, course_id: string|null, created_at: string|null, updated_at: string|null}>
     */
    private function ownedCardMediaPivotsFor(MediaAsset $mediaAsset): Collection
    {
        // Raw joins include soft-deleted cards/decks and avoid emitting tombstones for corrupt cross-owner pivots.
        // Pivots inserted after this snapshot may be cascade-deleted without tombstones; callers should not attach during asset deletion.
        return DB::table('card_media')
            ->select(
                'card_media.card_id',
                'cards.deck_id',
                'decks.course_id',
                'card_media.created_at',
                'card_media.updated_at',
            )
            ->join('cards', 'cards.id', '=', 'card_media.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('card_media.media_asset_id', $mediaAsset->id)
            ->where('decks.user_id', $mediaAsset->user_id)
            ->orderBy('card_media.card_id')
            ->get();
    }
}
