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

    public static function invalidMediaManifest(): self
    {
        return new self('The uploaded media manifest could not be parsed.');
    }

    public static function collectionDatabaseTooLarge(int $maxBytes): self
    {
        return new self('The uncompressed collection database must not exceed '.$maxBytes.' '.self::bytesLabel($maxBytes).'.');
    }

    public static function mediaManifestTooLarge(int $maxBytes): self
    {
        return new self('The uncompressed media manifest must not exceed '.$maxBytes.' '.self::bytesLabel($maxBytes).'.');
    }

    public static function mediaExpansionTooLarge(int $maxBytes): self
    {
        return new self('The uncompressed media referenced by the archive manifest must not exceed '.$maxBytes.' '.self::bytesLabel($maxBytes).'.');
    }

    public static function deckNameTooLong(int $maxCharacters): self
    {
        return new self('The imported deck name must not exceed '.$maxCharacters.' characters.');
    }

    public static function noteTypeNameTooLong(int $maxCharacters): self
    {
        return new self('Imported note type names must not exceed '.$maxCharacters.' characters.');
    }

    public static function invalidDeckNameEncoding(): self
    {
        return new self('The imported deck name must use valid UTF-8.');
    }

    public static function invalidNoteTypeNameEncoding(): self
    {
        return new self('Imported note type names must use valid UTF-8.');
    }

    public static function invalidCardTextEncoding(): self
    {
        return new self('Imported card text must use valid UTF-8.');
    }

    private static function bytesLabel(int $bytes): string
    {
        return $bytes === 1 ? 'byte' : 'bytes';
    }
}
