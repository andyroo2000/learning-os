<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Throwable;

final class StudyImportArchiveReader
{
    public function __construct(
        private readonly StudyImportCollectionDatabaseReader $collectionReader,
        private readonly StudyImportCollectionExtractor $collectionExtractor,
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
            $collectionPath = $this->collectionExtractor->extract($zip);
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

    private function tempPath(string $prefix): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary study import file.');
        }

        return $tempPath;
    }
}
