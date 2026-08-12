<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\CardMediaOwnership;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $card = DB::transaction(function () use ($data, $ownerUserId): Card {
            // Serialize detachment with direct card deletion and the card update performed by Deck's
            // soft-delete cascade. Re-resolving the live row also rejects an already-deleted parent deck.
            $card = Card::query()
                ->whereKey($data->card->getKey())
                ->whereHas('deck', fn ($query) => $query->where('user_id', $ownerUserId))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(Card::class, [$data->card->getKey()]);
            }

            // Serialize the pivot snapshot with DeleteMediaAssetAction. If deletion already won,
            // its FK cascade achieved the requested detached state and this remains a no-op.
            $mediaAsset = MediaAsset::query()
                ->whereKey($data->mediaAsset->getKey())
                ->where('user_id', $ownerUserId)
                ->lockForUpdate()
                ->first();

            if ($mediaAsset === null) {
                return $card;
            }

            $pivot = $this->pivotFor($card, $mediaAsset->id);
            $detachedCount = $card->mediaAssets()->detach($mediaAsset->id);

            if ($detachedCount < 1) {
                return $card;
            }

            $card->touch();

            $this->recordCardMediaSyncFeedEntry->handle(
                userId: $ownerUserId,
                operation: SyncFeedOperation::Delete,
                cardId: $card->id,
                mediaAssetId: $mediaAsset->id,
                deckId: $card->deck_id,
                courseId: $card->deckCourseId(),
                createdAt: $pivot?->created_at,
                updatedAt: $pivot?->updated_at,
            );

            return $card;
        });

        return $card->load('mediaAssets');
    }

    private function pivotFor(Card $card, string $mediaAssetId): ?object
    {
        return DB::table('card_media')
            ->where('card_id', $card->id)
            ->where('media_asset_id', $mediaAssetId)
            ->first(['created_at', 'updated_at']);
    }
}
