<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Illuminate\Filesystem\FilesystemAdapter;
use JsonException;
use RuntimeException;
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
    ) {}

    public function read(FilesystemAdapter $disk, string $sourceObjectPath): StudyImportArchiveRead
    {
        $archivePath = $this->copyStorageObjectToTempFile($disk, $sourceObjectPath);
        $collectionPath = null;
        $zip = null;

        try {
            $zip = $this->openArchive($archivePath);
            $collectionPath = $this->extractCollectionDatabase($zip);

            return $this->collectionReader->read(
                $collectionPath,
                $this->mediaManifestByFilename($zip),
            );
        } finally {
            $zip?->close();
            @unlink($archivePath);

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

        $archivePath = $this->copyStorageObjectToTempFile($sourceDisk, $sourceObjectPath);
        $zip = null;

        try {
            $zip = $this->openArchive($archivePath);
            $copied = [];

            foreach ($targetPathsBySourceMediaRef as $sourceMediaRef => $targetPath) {
                $stream = $zip->getStream((string) $sourceMediaRef);

                if ($stream === false) {
                    $copied[(string) $sourceMediaRef] = false;

                    continue;
                }

                try {
                    $copied[(string) $sourceMediaRef] = $targetDisk->put($targetPath, $stream);
                } finally {
                    fclose($stream);
                }
            }

            return $copied;
        } finally {
            $zip?->close();
            @unlink($archivePath);
        }
    }

    private function copyStorageObjectToTempFile(FilesystemAdapter $disk, string $sourceObjectPath): string
    {
        $input = $disk->readStream($sourceObjectPath);

        if ($input === false || $input === null) {
            throw StudyImportPreviewException::missingCollectionDatabase();
        }

        $tempPath = $this->tempPath('study-import-archive-');
        $output = fopen($tempPath, 'wb');

        if ($output === false) {
            fclose($input);

            throw new RuntimeException('Unable to create a temporary import archive file.');
        }

        try {
            stream_copy_to_stream($input, $output);

            return $tempPath;
        } finally {
            fclose($input);
            fclose($output);
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
            $stream = $zip->getStream($entryName);

            if ($stream === false) {
                continue;
            }

            try {
                return $this->copyCollectionStreamToTempFile($stream);
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
        $stream = $zip->getStream('media');

        if ($stream === false) {
            return [];
        }

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
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

            foreach ($decoded as $sourceMediaRef => $filename) {
                if (! is_string($filename) || str_contains($filename, "\0")) {
                    continue;
                }

                $filename = trim($filename);

                if ($filename === '' || array_key_exists($filename, $manifest)) {
                    continue;
                }

                $sourceMediaRef = (string) $sourceMediaRef;
                $contentMetadata = $this->mediaContentMetadata($zip, $sourceMediaRef);

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
    private function mediaContentMetadata(ZipArchive $zip, string $sourceMediaRef): array
    {
        $index = $zip->locateName($sourceMediaRef);

        if ($index === false) {
            return [
                'has_content' => false,
                'size_bytes' => null,
                'checksum_sha256' => null,
            ];
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
            hash_update_stream($hashContext, $stream);
            $stat = $zip->statIndex($index);

            return [
                'has_content' => true,
                'size_bytes' => is_array($stat) && isset($stat['size']) && is_numeric($stat['size'])
                    ? (int) $stat['size']
                    : null,
                'checksum_sha256' => hash_final($hashContext),
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function copyCollectionStreamToTempFile($stream): string
    {
        $collectionPath = $this->tempPath('study-import-collection-');
        $output = fopen($collectionPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to create a temporary collection database file.');
        }

        try {
            $header = fread($stream, 4);

            if ($header === false) {
                throw StudyImportPreviewException::invalidCollectionDatabase();
            }

            if ($header === self::ZSTD_MAGIC) {
                throw StudyImportPreviewException::unsupportedCompressedCollectionDatabase();
            }

            fwrite($output, $header);
            stream_copy_to_stream($stream, $output);

            return $collectionPath;
        } catch (StudyImportPreviewException $exception) {
            @unlink($collectionPath);

            throw $exception;
        } finally {
            fclose($output);
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
