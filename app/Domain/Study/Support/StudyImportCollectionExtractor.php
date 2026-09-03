<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class StudyImportCollectionExtractor
{
    private const COLLECTION_DATABASE_ENTRIES = [
        'collection.anki21b',
        'collection.anki21',
        'collection.anki2',
    ];

    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";

    public function __construct(
        private readonly StudyImportArchiveExpansionPolicy $expansionPolicy,
        private readonly StudyImportArchiveAccess $archiveAccess,
    ) {}

    public function extract(ZipArchive $zip): string
    {
        foreach (self::COLLECTION_DATABASE_ENTRIES as $entryName) {
            $index = $zip->locateName($entryName);

            if ($index === false) {
                continue;
            }

            return $this->extractEntry($zip, $entryName, $index);
        }

        throw StudyImportPreviewException::missingCollectionDatabase();
    }

    private function extractEntry(ZipArchive $zip, string $entryName, int $index): string
    {
        $declaredSize = $this->archiveAccess->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        $maxBytes = $this->expansionPolicy->maxCollectionDatabaseBytes();

        if ($declaredSize > $maxBytes) {
            throw StudyImportPreviewException::collectionDatabaseTooLarge($maxBytes);
        }

        $stream = $zip->getStream($entryName);

        if ($stream === false) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        try {
            return $this->copyCollectionStreamToTempFile($stream, $declaredSize);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function copyCollectionStreamToTempFile($stream, int $declaredSize): string
    {
        $collectionPath = $this->tempPath();

        try {
            $this->copyCollectionStream($stream, $collectionPath, $declaredSize);

            return $collectionPath;
        } catch (Throwable $exception) {
            @unlink($collectionPath);

            $this->rethrowCopyFailure($exception);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function copyCollectionStream($stream, string $collectionPath, int $declaredSize): void
    {
        $output = fopen($collectionPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to create a temporary collection database file.');
        }

        try {
            $this->copyCollectionBytes($stream, $output, $declaredSize);
        } finally {
            @fclose($output);
        }
    }

    /**
     * @param  resource  $stream
     * @param  resource  $output
     */
    private function copyCollectionBytes($stream, $output, int $declaredSize): void
    {
        $header = $this->validatedHeader($stream);
        $headerBytes = strlen($header);
        $this->writeHeader($output, $header, $headerBytes);
        $copiedBytes = $this->copyRemainingBytes($stream, $output, $declaredSize, $headerBytes);

        if ($headerBytes + $copiedBytes !== $declaredSize) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }
    }

    /**
     * @param  resource  $stream
     */
    private function validatedHeader($stream): string
    {
        $header = fread($stream, 4);

        if ($header === false) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        if ($header === self::ZSTD_MAGIC) {
            throw StudyImportPreviewException::unsupportedCompressedCollectionDatabase();
        }

        return $header;
    }

    /**
     * @param  resource  $stream
     * @param  resource  $output
     */
    private function copyRemainingBytes($stream, $output, int $declaredSize, int $headerBytes): int
    {
        $remainingByteLimit = max(1, $declaredSize - $headerBytes + 1);
        $copiedBytes = @stream_copy_to_stream($stream, $output, $remainingByteLimit);

        if ($copiedBytes === false) {
            throw new RuntimeException('Unable to copy the collection database to temporary storage.');
        }

        if (! fflush($output)) {
            throw new RuntimeException('Unable to copy the collection database to temporary storage.');
        }

        return $copiedBytes;
    }

    /**
     * @param  resource  $output
     */
    private function writeHeader($output, string $header, int $headerBytes): void
    {
        if ($header === '') {
            return;
        }

        if (@fwrite($output, $header) !== $headerBytes) {
            throw new RuntimeException('Unable to copy the collection database to temporary storage.');
        }
    }

    private function rethrowCopyFailure(Throwable $exception): never
    {
        if ($exception instanceof StudyImportPreviewException) {
            throw $exception;
        }

        if ($exception instanceof RuntimeException) {
            throw $exception;
        }

        throw new RuntimeException(
            'Unable to copy the collection database to temporary storage.',
            previous: $exception,
        );
    }

    private function tempPath(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'study-import-collection-');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary study import file.');
        }

        return $tempPath;
    }
}
