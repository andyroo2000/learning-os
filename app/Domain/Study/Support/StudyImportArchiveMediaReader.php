<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Illuminate\Filesystem\FilesystemAdapter;
use JsonException;
use RuntimeException;
use ZipArchive;

final class StudyImportArchiveMediaReader
{
    public function __construct(
        private readonly StudyImportArchiveExpansionPolicy $expansionPolicy,
        private readonly StudyImportArchiveAccess $archiveAccess,
    ) {}

    /**
     * @param  array<string, string>  $targetPathsBySourceMediaRef
     * @return array<string, bool>
     */
    public function copyEntriesToDisk(
        StudyImportArchiveSnapshot $snapshot,
        FilesystemAdapter $targetDisk,
        array $targetPathsBySourceMediaRef,
    ): array {
        if ($targetPathsBySourceMediaRef === []) {
            return [];
        }

        $zip = $this->archiveAccess->open($snapshot->path());

        try {
            return $this->copyRequestedEntries($zip, $targetDisk, $targetPathsBySourceMediaRef);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, true>  $referencedFilenames
     * @return array<string, StudyImportArchiveMediaEntry>
     */
    public function manifestByFilename(ZipArchive $zip, array $referencedFilenames): array
    {
        $contents = $this->manifestContents($zip);

        if ($contents === null) {
            return [];
        }

        return $this->buildManifest($zip, $this->decodeManifest($contents), $referencedFilenames);
    }

    /**
     * @param  array<string, string>  $targetPathsBySourceMediaRef
     * @return array<string, bool>
     */
    private function copyRequestedEntries(
        ZipArchive $zip,
        FilesystemAdapter $targetDisk,
        array $targetPathsBySourceMediaRef,
    ): array {
        $copied = [];
        $budget = new StudyImportArchiveMediaBudget($this->expansionPolicy);

        foreach ($targetPathsBySourceMediaRef as $sourceMediaRef => $targetPath) {
            $target = new StudyImportArchiveMediaCopyTarget((string) $sourceMediaRef, $targetPath);
            $copied[$target->sourceMediaRef] = $this->copyEntry(
                $zip,
                $targetDisk,
                $target,
                $budget,
            );
        }

        return $copied;
    }

    private function copyEntry(
        ZipArchive $zip,
        FilesystemAdapter $targetDisk,
        StudyImportArchiveMediaCopyTarget $target,
        StudyImportArchiveMediaBudget $budget,
    ): bool {
        $index = $zip->locateName($target->sourceMediaRef);

        if ($index === false) {
            return false;
        }

        $declaredSize = $this->archiveAccess->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            return false;
        }

        if ($declaredSize > $this->expansionPolicy->maxIndividualMediaBytes()) {
            return false;
        }

        $source = new StudyImportArchiveMediaSource($target->sourceMediaRef, $declaredSize);

        if (! $budget->tryConsume($source)) {
            return false;
        }

        return $this->copyEntryStream($zip, $targetDisk, $target, $source);
    }

    private function copyEntryStream(
        ZipArchive $zip,
        FilesystemAdapter $targetDisk,
        StudyImportArchiveMediaCopyTarget $target,
        StudyImportArchiveMediaSource $source,
    ): bool {
        $stream = $zip->getStream($source->reference);

        if ($stream === false) {
            return false;
        }

        $boundedStream = fopen('php://temp/maxmemory:5242880', 'w+b');

        if ($boundedStream === false) {
            fclose($stream);

            throw new RuntimeException('Unable to create a bounded study import media stream.');
        }

        try {
            $copiedBytes = @stream_copy_to_stream($stream, $boundedStream, $source->declaredSize + 1);

            if ($copiedBytes !== $source->declaredSize) {
                return false;
            }

            if (! rewind($boundedStream)) {
                return false;
            }

            return $targetDisk->put($target->targetPath, $boundedStream);
        } finally {
            fclose($stream);
            fclose($boundedStream);
        }
    }

    private function manifestContents(ZipArchive $zip): ?string
    {
        $index = $zip->locateName('media');

        if ($index === false) {
            return null;
        }

        $declaredSize = $this->declaredManifestSize($zip, $index);

        $stream = $zip->getStream('media');

        if ($stream === false) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        return $this->readManifestStream($stream, $declaredSize);
    }

    private function declaredManifestSize(ZipArchive $zip, int $index): int
    {
        $declaredSize = $this->archiveAccess->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        $maxBytes = $this->expansionPolicy->maxMediaManifestBytes();

        if ($declaredSize > $maxBytes) {
            throw StudyImportPreviewException::mediaManifestTooLarge($maxBytes);
        }

        return $declaredSize;
    }

    /** @param resource $stream */
    private function readManifestStream($stream, int $declaredSize): string
    {
        try {
            $contents = @stream_get_contents($stream, $declaredSize + 1);

            if ($contents === false) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            if (strlen($contents) !== $declaredSize) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<mixed> */
    private function decodeManifest(string $contents): array
    {
        try {
            $decoded = json_decode(str_replace("\0", '', $contents), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<mixed>  $decoded
     * @param  array<string, true>  $referencedFilenames
     * @return array<string, StudyImportArchiveMediaEntry>
     */
    private function buildManifest(ZipArchive $zip, array $decoded, array $referencedFilenames): array
    {
        $manifest = [];
        $budget = new StudyImportArchiveMediaBudget($this->expansionPolicy);

        foreach ($decoded as $sourceMediaRef => $filename) {
            $filename = $this->referencedFilename($filename, $referencedFilenames, $manifest);

            if ($filename === null) {
                continue;
            }

            $sourceMediaRef = (string) $sourceMediaRef;
            $contentMetadata = $this->mediaContentMetadata($zip, $sourceMediaRef, $budget);
            $manifest[$filename] = new StudyImportArchiveMediaEntry(
                sourceMediaRef: $sourceMediaRef,
                sourceFilename: $filename,
                hasContent: $contentMetadata['has_content'],
                sizeBytes: $contentMetadata['size_bytes'],
                checksumSha256: $contentMetadata['checksum_sha256'],
            );
        }

        return $manifest;
    }

    /**
     * @param  array<string, true>  $referencedFilenames
     * @param  array<string, StudyImportArchiveMediaEntry>  $manifest
     */
    private function referencedFilename(mixed $filename, array $referencedFilenames, array $manifest): ?string
    {
        if (! is_string($filename)) {
            return null;
        }

        if (str_contains($filename, "\0")) {
            return null;
        }

        $filename = trim($filename);

        if ($filename === '') {
            return null;
        }

        if (! isset($referencedFilenames[$filename])) {
            return null;
        }

        return array_key_exists($filename, $manifest) ? null : $filename;
    }

    /**
     * @return array{has_content: bool, size_bytes: int|null, checksum_sha256: string|null}
     */
    private function mediaContentMetadata(
        ZipArchive $zip,
        string $sourceMediaRef,
        StudyImportArchiveMediaBudget $budget,
    ): array {
        $index = $zip->locateName($sourceMediaRef);

        if ($index === false) {
            return $this->missingContentMetadata();
        }

        $declaredSize = $this->archiveAccess->declaredEntrySize($zip, $index);

        if ($declaredSize === null) {
            throw StudyImportPreviewException::invalidMediaManifest();
        }

        $source = new StudyImportArchiveMediaSource($sourceMediaRef, $declaredSize);

        if ($source->declaredSize > $this->expansionPolicy->maxIndividualMediaBytes()) {
            return $this->oversizedContentMetadata($source);
        }

        $budget->consumeForManifest($source);

        return $this->hashedContentMetadata($zip, $source);
    }

    /** @return array{has_content: false, size_bytes: null, checksum_sha256: null} */
    private function missingContentMetadata(): array
    {
        return [
            'has_content' => false,
            'size_bytes' => null,
            'checksum_sha256' => null,
        ];
    }

    /** @return array{has_content: true, size_bytes: int, checksum_sha256: null} */
    private function oversizedContentMetadata(StudyImportArchiveMediaSource $source): array
    {
        return [
            'has_content' => true,
            'size_bytes' => $source->declaredSize,
            'checksum_sha256' => null,
        ];
    }

    /** @return array{has_content: bool, size_bytes: int|null, checksum_sha256: string|null} */
    private function hashedContentMetadata(ZipArchive $zip, StudyImportArchiveMediaSource $source): array
    {
        $stream = $zip->getStream($source->reference);

        if ($stream === false) {
            return $this->missingContentMetadata();
        }

        try {
            $hashContext = hash_init('sha256');
            $hashedBytes = @hash_update_stream($hashContext, $stream, $source->declaredSize + 1);

            if ($hashedBytes !== $source->declaredSize) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            return [
                'has_content' => true,
                'size_bytes' => $source->declaredSize,
                'checksum_sha256' => hash_final($hashContext),
            ];
        } finally {
            fclose($stream);
        }
    }
}
