<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;
use Throwable;

final class StudyImportPreviewException extends RuntimeException
{
    private const INVALID_COLLECTION_DATABASE_MESSAGE = 'The uploaded collection database could not be parsed.';

    public static function missingCollectionDatabase(): self
    {
        return new self('The uploaded .colpkg does not contain a collection database.');
    }

    public static function unsupportedCompressedCollectionDatabase(): self
    {
        return new self('Zstd-compressed Anki collection databases are not supported yet.');
    }

    public static function invalidCollectionDatabase(?Throwable $previous = null): self
    {
        return new self(self::INVALID_COLLECTION_DATABASE_MESSAGE, previous: $previous);
    }

    public function isInvalidCollectionDatabase(): bool
    {
        return $this->getMessage() === self::INVALID_COLLECTION_DATABASE_MESSAGE;
    }

    public static function invalidMediaManifest(): self
    {
        return new self('The uploaded media manifest could not be parsed.');
    }
}
