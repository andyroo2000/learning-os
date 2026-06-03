<?php

namespace App\Domain\Media\Exceptions;

use DomainException;

final class MediaOwnershipException extends DomainException
{
    public static function cardMediaOwnerMismatchWithContext(
        string|int $cardId,
        int $cardOwnerUserId,
        string|int $mediaAssetId,
        int $mediaOwnerUserId,
    ): self {
        return new self("Card {$cardId} owner {$cardOwnerUserId} and media asset {$mediaAssetId} owner {$mediaOwnerUserId} differ.");
    }
}
