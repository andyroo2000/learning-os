<?php

namespace App\Domain\Media\Exceptions;

use DomainException;

/**
 * Raised for direct action callers; HTTP requests resolve models before calling the action.
 */
final class CannotAttachMediaToCard extends DomainException
{
    public static function missingCard(): self
    {
        return new self('Card does not exist.');
    }

    public static function missingMediaAsset(): self
    {
        return new self('Media asset does not exist.');
    }
}
