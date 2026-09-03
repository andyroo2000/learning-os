<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Throwable;
use ZipArchive;

final class StudyImportArchiveReader
{
    private const COLLECTION_DATABASE_ENTRIES = [
        'collection.anki21b',
        'collection.anki21',
        'collection.anki2',
    ];

    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";

    public function __construct(
        private readonly StudyImportCollectionDatabaseReader $collectionReader,
        private readonly StudyImportArchiveExpansionPolicy $expansionPolicy,
        private readonly StudyImportArchiveMediaReader $mediaReader,
        private readonly StudyImportArchiveAccess $archiveAccess,
    ) {}

    public function read(FilesystemAdapter $disk, string $sourceObjectPath): StudyImportArchiveRead
    {
        $snapshot = $this->snapshot($disk, $sourceObjectPath);

        try {
            return $this->readSnapshot($snapshot);
        } finally {
            $snapshot->close();
        }
    }

    public function snapshot(FilesystemAdapter $disk, string $sourceObjectPath): StudyImportArchiveSnapshot
    {
        return new StudyImportArchiveSnapshot(
            $this->copyStorageObjectToTempFile($disk, $sourceObjectPath),
        );
    }

    public function readSnapshot(StudyImportArchiveSnapshot $snapshot): StudyImportArchiveRead
    {
        $collectionPath = null;
        $zip = null;

        try {
            $zip = $this->archiveAccess->open($snapshot->path());
            $collectionPath = $this->extractCollectionDatabase($zip);
            $collection = $this->collectionReader->read($collectionPath);

            return new StudyImportArchiveRead(
                deckName: $collection->deckName,
                cards: $collection->cards,
                reviewLogs: $collection->reviewLogs,
                mediaManifestByFilename: $this->mediaReader->manifestByFilename(
                    $zip,
                    array_fill_keys($collection->mediaReferences(), true),
                ),
            );
        } finally {
            $zip?->close();

            if ($collectionPath !== null) {
                @unlink($collectionPath);
            }
        }
    }

    /**
     * @param  array<string, string>  $targetPathsBySourceMediaRef
     * @return array<string, bool>
     */
    public function copyMediaEntriesFromSnapshotToDisk(
        StudyImportArchiveSnapshot $snapshot,
        FilesystemAdapter $targetDisk,
        array $targetPathsBySourceMediaRef,
    ): array {
        return $this->mediaReader->copyEntriesToDisk($snapshot, $targetDisk, $targetPathsBySourceMediaRef);
    }

    private function copyStorageObjectToTempFile(FilesystemAdapter $disk, string $sourceObjectPath): string
    {
        $input = $disk->readStream($sourceObjectPath);

        if ($input === false || $input === null) {
            throw StudyImportPreviewException::missingCollectionDatabase();
        }

        $tempPath = null;
        $output = null;
        $copyCompleted = false;

        try {
            $tempPath = $this->tempPath('study-import-archive-');
            $output = fopen($tempPath, 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to create a temporary import archive file.');
            }

            if (@stream_copy_to_stream($input, $output) === false || ! fflush($output)) {
                throw new RuntimeException('Unable to copy the import archive to temporary storage.');
            }

            $copyCompleted = true;

            return $tempPath;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Unable to copy the import archive to temporary storage.',
                previous: $exception,
            );
        } finally {
            @fclose($input);

            if (is_resource($output)) {
                @fclose($output);
            }

            if (! $copyCompleted && $tempPath !== null) {
                @unlink($tempPath);
            }
        }
    }

    private function extractCollectionDatabase(ZipArchive $zip): string
    {
        foreach (self::COLLECTION_DATABASE_ENTRIES as $entryName) {
            $index = $zip->locateName($entryName);

            if ($index === false) {
                continue;
            }

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

        throw StudyImportPreviewException::missingCollectionDatabase();
    }

    /**
     * @param  resource  $stream
     */
    private function copyCollectionStreamToTempFile($stream, int $declaredSize): string
    {
        $collectionPath = null;
        $output = null;
        $copyCompleted = false;

        try {
            $collectionPath = $this->tempPath('study-import-collection-');
            $output = fopen($collectionPath, 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to create a temporary collection database file.');
            }

            $header = fread($stream, 4);

            if ($header === false) {
                throw StudyImportPreviewException::invalidCollectionDatabase();
            }

            if ($header === self::ZSTD_MAGIC) {
                throw StudyImportPreviewException::unsupportedCompressedCollectionDatabase();
            }

            $headerBytes = strlen($header);

            if ($header !== '' && @fwrite($output, $header) !== $headerBytes) {
                throw new RuntimeException('Unable to copy the collection database to temporary storage.');
            }

            $remainingByteLimit = max(1, $declaredSize - $headerBytes + 1);
            $copiedBytes = @stream_copy_to_stream($stream, $output, $remainingByteLimit);

            if ($copiedBytes === false || ! fflush($output)) {
                throw new RuntimeException('Unable to copy the collection database to temporary storage.');
            }

            if ($headerBytes + $copiedBytes !== $declaredSize) {
                throw StudyImportPreviewException::invalidCollectionDatabase();
            }

            $copyCompleted = true;

            return $collectionPath;
        } catch (Throwable $exception) {
            if ($exception instanceof StudyImportPreviewException || $exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Unable to copy the collection database to temporary storage.',
                previous: $exception,
            );
        } finally {
            if (is_resource($output)) {
                @fclose($output);
            }

            if (! $copyCompleted && $collectionPath !== null) {
                @unlink($collectionPath);
            }
        }
    }

    private function tempPath(string $prefix): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary study import file.');
        }

        return $tempPath;
    }
}
