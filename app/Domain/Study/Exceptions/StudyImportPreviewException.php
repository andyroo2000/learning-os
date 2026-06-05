<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

final class StudyImportPreviewException extends RuntimeException
{
    public static function missingCollectionDatabase(): self
    {
        return new self('The uploaded .colpkg does not contain a collection database.');
    }

    public static function unsupportedCompressedCollectionDatabase(): self
    {
        return new self('Zstd-compressed Anki collection databases are not supported yet.');
    }

    public static function invalidCollectionDatabase(): self
    {
        return new self('The uploaded collection database could not be parsed.');
    }
}
