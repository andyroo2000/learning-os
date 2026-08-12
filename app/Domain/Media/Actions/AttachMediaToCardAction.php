<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Support\CardMediaOwnership;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $card = DB::transaction(function () use ($data, $ownerUserId): Card {
            // Serialize attachment with direct card deletion and the card update performed by Deck's
            // soft-delete cascade. Re-resolving the live row also rejects an already-deleted parent deck.
            $card = Card::query()
                ->whereKey($data->card->getKey())
                ->whereHas('deck', fn ($query) => $query->where('user_id', $ownerUserId))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(Card::class, [$data->card->getKey()]);
            }

            $changes = $card->mediaAssets()->syncWithoutDetaching([$data->mediaAsset->id]);

            if ($changes['attached'] === []) {
                return $card;
            }

            $card->touch();
            $pivot = $this->pivotFor($card, $data->mediaAsset->id)
                ?? throw new RuntimeException('Card media pivot missing after attach.');

            $this->recordCardMediaSyncFeedEntry->handle(
                userId: $ownerUserId,
                operation: SyncFeedOperation::Create,
                cardId: $card->id,
                mediaAssetId: $data->mediaAsset->id,
                deckId: $card->deck_id,
                courseId: $card->deckCourseId(),
                createdAt: $pivot->created_at,
                updatedAt: $pivot->updated_at,
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
