<?php

namespace App\Domain\Media\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use LogicException;

final class CardMediaOwnership
{
    /**
     * Refreshes the card deck relation with trashed decks; callers should treat that relation cache as mutable.
     */
    public static function ownerUserIdFor(Card $card, MediaAsset $mediaAsset): int
    {
        // Read the raw value before Eloquent's integer cast can hide malformed numeric strings.
        $mediaOwnerUserId = self::resolveMediaOwnerUserId($mediaAsset->getRawOriginal('user_id'));

        // Prefer the cheap media-owner invariant first; corrupt card owners still surface as 500-level failures below.
        // Intentionally clobber the relation cache with trashed decks before owner resolution.
        // This pre-transaction check relies on deck ownership remaining immutable after creation.
        $card->load(['deck' => fn ($query) => $query->withTrashed()]);

        $cardOwnerUserId = $card->ownerUserId();

        if ($cardOwnerUserId !== $mediaOwnerUserId) {
            throw MediaOwnershipException::cardMediaOwnerMismatchWithContext(
                cardId: $card->getKey(),
                cardOwnerUserId: $cardOwnerUserId,
                mediaAssetId: $mediaAsset->getKey(),
                mediaOwnerUserId: $mediaOwnerUserId,
            );
        }

        return $cardOwnerUserId;
    }

    private static function resolveMediaOwnerUserId(int|string|null $userId): int
    {
        if (is_string($userId) && ! ctype_digit($userId)) {
            throw new LogicException('Media asset owner could not be resolved.');
        }

        $resolvedUserId = (int) $userId;

        // Non-positive owners indicate corrupt data; surface that as a 500-level invariant failure, not a 404.
        if ($resolvedUserId <= 0) {
            throw new LogicException('Media asset owner could not be resolved.');
        }

        return $resolvedUserId;
    }
}
