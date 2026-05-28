<?php

namespace App\Domain\Media\Exceptions;

use DomainException;

final class CannotAttachMediaToCard extends DomainException
{
    public static function invalidCardId(): self
    {
        return new self('Card ID must be a valid ULID.');
    }

    public static function invalidMediaAssetId(): self
    {
        return new self('Media asset ID must be a valid ULID.');
    }

    public static function missingCard(): self
    {
        return new self('Card does not exist.');
    }

    public static function missingMediaAsset(): self
    {
        return new self('Media asset does not exist.');
    }
}
