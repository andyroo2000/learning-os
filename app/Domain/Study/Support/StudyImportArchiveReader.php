<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use JsonException;
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
            $zip = $this->openArchive($snapshot->path());
            $collectionPath = $this->extractCollectionDatabase($zip);

            return $this->collectionReader->read(
                $collectionPath,
                $this->mediaManifestByFilename($zip),
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
    public function copyMediaEntriesToDisk(
        FilesystemAdapter $sourceDisk,
        string $sourceObjectPath,
        FilesystemAdapter $targetDisk,
        array $targetPathsBySourceMediaRef,
    ): array {
        if ($targetPathsBySourceMediaRef === []) {
            return [];
        }

        $snapshot = $this->snapshot($sourceDisk, $sourceObjectPath);

        try {
            return $this->copyMediaEntriesFromSnapshotToDisk($snapshot, $targetDisk, $targetPathsBySourceMediaRef);
        } finally {
            $snapshot->close();
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
        if ($targetPathsBySourceMediaRef === []) {
            return [];
        }

        $zip = null;

        try {
            $zip = $this->openArchive($snapshot->path());
            $copied = [];
            $consumedMediaBytes = 0;

            foreach ($targetPathsBySourceMediaRef as $sourceMediaRef => $targetPath) {
                $sourceMediaRef = (string) $sourceMediaRef;
                $index = $zip->locateName($sourceMediaRef);
                $declaredSize = $index === false ? null : $this->declaredEntrySize($zip, $index);

                if ($declaredSize === null || $declaredSize > $this->expansionPolicy->maxIndividualMediaBytes()) {
                    $copied[$sourceMediaRef] = false;

                    continue;
                }

                try {
                    $consumedMediaBytes = $this->expansionPolicy->addMediaBytes($consumedMediaBytes, $declaredSize);
                } catch (InvalidArgumentException) {
                    $copied[$sourceMediaRef] = false;

                    continue;
                }

                $stream = $zip->getStream((string) $sourceMediaRef);

                if ($stream === false) {
                    $copied[$sourceMediaRef] = false;

                    continue;
                }

                $boundedStream = fopen('php://temp/maxmemory:5242880', 'w+b');

                if ($boundedStream === false) {
                    fclose($stream);

                    throw new RuntimeException('Unable to create a bounded study import media stream.');
                }

                try {
                    $copiedBytes = @stream_copy_to_stream($stream, $boundedStream, $declaredSize + 1);

                    if ($copiedBytes !== $declaredSize || ! rewind($boundedStream)) {
                        $copied[$sourceMediaRef] = false;

                        continue;
                    }

                    $copied[$sourceMediaRef] = $targetDisk->put($targetPath, $boundedStream);
                } finally {
                    fclose($stream);
                    fclose($boundedStream);
                }
            }

            return $copied;
        } finally {
            $zip?->close();
        }
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

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        return $zip;
    }

    private function extractCollectionDatabase(ZipArchive $zip): string
    {
        foreach (self::COLLECTION_DATABASE_ENTRIES as $entryName) {
            $index = $zip->locateName($entryName);

            if ($index === false) {
                continue;
            }

            $declaredSize = $this->declaredEntrySize($zip, $index);

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
     * @return array<string, StudyImportArchiveMediaEntry>
     */
    private function mediaManifestByFilename(ZipArchive $zip): array
    {
        $index = $zip->locateName('media');

        if ($index === false) {
            return [];
        }

        $declaredSize = $this->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        $maxBytes = $this->expansionPolicy->maxMediaManifestBytes();

        if ($declaredSize > $maxBytes) {
            throw StudyImportPreviewException::mediaManifestTooLarge($maxBytes);
        }

        $stream = $zip->getStream('media');

        if ($stream === false) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        try {
            $contents = @stream_get_contents($stream, $declaredSize + 1);

            if ($contents === false || strlen($contents) !== $declaredSize) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            try {
                $decoded = json_decode(str_replace("\0", '', $contents), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            if (! is_array($decoded)) {
                return [];
            }

            $manifest = [];
            $consumedMediaBytes = 0;

            foreach ($decoded as $sourceMediaRef => $filename) {
                if (! is_string($filename) || str_contains($filename, "\0")) {
                    continue;
                }

                $filename = trim($filename);

                if ($filename === '' || array_key_exists($filename, $manifest)) {
                    continue;
                }

                $sourceMediaRef = (string) $sourceMediaRef;
                $contentMetadata = $this->mediaContentMetadata($zip, $sourceMediaRef, $consumedMediaBytes);

                $manifest[$filename] = new StudyImportArchiveMediaEntry(
                    sourceMediaRef: $sourceMediaRef,
                    sourceFilename: $filename,
                    hasContent: $contentMetadata['has_content'],
                    sizeBytes: $contentMetadata['size_bytes'],
                    checksumSha256: $contentMetadata['checksum_sha256'],
                );
            }

            return $manifest;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{has_content: bool, size_bytes: int|null, checksum_sha256: string|null}
     */
    private function mediaContentMetadata(ZipArchive $zip, string $sourceMediaRef, int &$consumedMediaBytes): array
    {
        $index = $zip->locateName($sourceMediaRef);

        if ($index === false) {
            return [
                'has_content' => false,
                'size_bytes' => null,
                'checksum_sha256' => null,
            ];
        }

        $declaredSize = $this->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        if ($declaredSize > $this->expansionPolicy->maxIndividualMediaBytes()) {
            return [
                'has_content' => true,
                'size_bytes' => $declaredSize,
                'checksum_sha256' => null,
            ];
        }

        try {
            $consumedMediaBytes = $this->expansionPolicy->addMediaBytes($consumedMediaBytes, $declaredSize);
        } catch (InvalidArgumentException) {
            throw StudyImportPreviewException::mediaExpansionTooLarge(
                $this->expansionPolicy->maxTotalMediaBytes(),
            );
        }

        $stream = $zip->getStream($sourceMediaRef);

        if ($stream === false) {
            return [
                'has_content' => false,
                'size_bytes' => null,
                'checksum_sha256' => null,
            ];
        }

        try {
            $hashContext = hash_init('sha256');
            $hashedBytes = @hash_update_stream($hashContext, $stream, $declaredSize + 1);

            if ($hashedBytes !== $declaredSize) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            return [
                'has_content' => true,
                'size_bytes' => $declaredSize,
                'checksum_sha256' => hash_final($hashContext),
            ];
        } finally {
            fclose($stream);
        }
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

    private function declaredEntrySize(ZipArchive $zip, int $index): ?int
    {
        $stat = $zip->statIndex($index);

        if (! is_array($stat) || ! array_key_exists('size', $stat)) {
            return null;
        }

        $size = filter_var($stat['size'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $size === false ? null : $size;
    }
}
