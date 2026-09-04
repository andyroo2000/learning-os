<?php

namespace App\Console\Concerns;

use App\Console\Support\ConvoLabMediaImportMappings;
use App\Console\Support\ConvoLabMediaImportState;
use App\Console\Support\ConvoLabMediaStoragePath;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\OriginalFilename;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use RuntimeException;

trait BuildsConvoLabMediaImportManifest
{
    private const MAX_SOURCE_KIND_LENGTH = 64;

    private const MAX_SOURCE_METADATA_LENGTH = 255;

    private ConvoLabMediaImportState $importState;

    private string $sourceMediaRoot;

    private ConvoLabMediaImportMappings $mediaMappings;

    private function buildMediaManifest(
        ConnectionInterface $source,
        string $sourceMediaRoot,
        ConvoLabMediaImportMappings $mappings,
    ): void {
        $this->sourceMediaRoot = $sourceMediaRoot;
        $this->mediaMappings = $mappings;
        $this->importState->mediaByPath = [];
        $this->importState->pathBySourceMediaId = [];
        $this->importState->userIdBySourceMediaId = [];
        $this->importState->unavailableSourceMediaIds = [];

        $source->table('study_media')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function (Collection $rows): void {
                foreach ($rows as $media) {
                    $this->addMediaToManifest($media);
                }
            });
    }

    private function addMediaToManifest(object $media): void
    {
        $sourceId = (string) $media->id;

        if ($this->hasNoStoragePath($media)) {
            $this->importState->unavailableSourceMediaIds[$sourceId] = true;

            return;
        }

        $userId = $this->mappedMediaUserId($media);
        $verifiedFile = $this->verifiedSourceFile($media, $sourceId, $this->sourceMediaRoot, $userId);

        if (isset($this->importState->mediaByPath[$verifiedFile['path']])) {
            $this->addSourceToExistingManifestPath($verifiedFile);
        } else {
            $this->importState->mediaByPath[$verifiedFile['path']] = $this->newMediaManifestEntry(
                $media,
                $verifiedFile,
                $this->mediaMappings,
            );
        }

        $this->importState->pathBySourceMediaId[$sourceId] = $verifiedFile['path'];
        $this->importState->userIdBySourceMediaId[$sourceId] = $userId;
    }

    private function hasNoStoragePath(object $media): bool
    {
        return ! is_string($media->storagePath) || trim($media->storagePath) === '';
    }

    private function mappedMediaUserId(object $media): int
    {
        $userId = $this->mediaMappings->userIds[(string) $media->userId] ?? null;

        if ($userId === null) {
            $sourceId = (string) $media->id;
            throw new RuntimeException("Missing Learning OS user mapping for media [{$sourceId}].");
        }

        return $userId;
    }

    /**
     * @return array{
     *     source_id: string,
     *     user_id: int,
     *     path: string,
     *     source_path: string,
     *     size_bytes: int,
     *     checksum_sha256: string
     * }
     */
    private function verifiedSourceFile(
        object $media,
        string $sourceId,
        string $sourceMediaRoot,
        int $userId,
    ): array {
        $path = ConvoLabMediaStoragePath::fromMedia($media)->value;
        $sourcePath = $this->resolveConvoLabSourceFile(
            $sourceMediaRoot,
            $path,
            "Convo Lab media bytes are missing for [{$sourceId}] at [{$path}].",
        );
        $size = filesize($sourcePath);
        $checksum = hash_file('sha256', $sourcePath);

        if (! $this->isValidSourceSize($size)) {
            throw new RuntimeException("Convo Lab media [{$sourceId}] has an invalid byte size.");
        }

        if (! is_string($checksum)) {
            throw new RuntimeException("Unable to checksum Convo Lab media [{$sourceId}].");
        }

        return [
            'source_id' => $sourceId,
            'user_id' => $userId,
            'path' => $path,
            'source_path' => $sourcePath,
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
        ];
    }

    private function isValidSourceSize(mixed $size): bool
    {
        return is_int($size) && $size >= 1 && $size <= MediaAsset::MAX_JSON_SAFE_SIZE_BYTES;
    }

    /**
     * @param  array{source_id: string, user_id: int, path: string, size_bytes: int, checksum_sha256: string}  $verifiedFile
     */
    private function addSourceToExistingManifestPath(array $verifiedFile): void
    {
        $path = $verifiedFile['path'];
        $existing = $this->importState->mediaByPath[$path];

        if ($existing['user_id'] !== $verifiedFile['user_id']) {
            throw new RuntimeException("Media path [{$path}] is shared by multiple Convo Lab users.");
        }

        if ($existing['size_bytes'] !== $verifiedFile['size_bytes']
            || $existing['checksum_sha256'] !== $verifiedFile['checksum_sha256']) {
            throw new RuntimeException("Media path [{$path}] resolves to inconsistent source bytes.");
        }

        $this->importState->mediaByPath[$path]['source_ids'][] = $verifiedFile['source_id'];
    }

    /**
     * @param  array{
     *     source_id: string,
     *     user_id: int,
     *     path: string,
     *     source_path: string,
     *     size_bytes: int,
     *     checksum_sha256: string
     * }  $verifiedFile
     * @return array{
     *     source_ids: list<string>,
     *     user_id: int,
     *     import_job_id: string|null,
     *     path: string,
     *     source_path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     checksum_sha256: string,
     *     original_filename: string|null,
     *     source_kind: string|null,
     *     source_media_ref: string|null,
     *     source_filename: string|null,
     *     created_at: mixed,
     *     updated_at: mixed
     * }
     */
    private function newMediaManifestEntry(
        object $media,
        array $verifiedFile,
        ConvoLabMediaImportMappings $mappings,
    ): array {
        $sourceId = $verifiedFile['source_id'];
        $sourceImportJobId = is_string($media->importJobId) ? $media->importJobId : null;

        return [
            'source_ids' => [$sourceId],
            'user_id' => $verifiedFile['user_id'],
            'import_job_id' => $sourceImportJobId === null
                ? null
                : ($mappings->importJobIds[$sourceImportJobId] ?? null),
            'path' => $verifiedFile['path'],
            'source_path' => $verifiedFile['source_path'],
            'mime_type' => $this->mimeType($media->contentType, $sourceId),
            'size_bytes' => $verifiedFile['size_bytes'],
            'checksum_sha256' => $verifiedFile['checksum_sha256'],
            'original_filename' => $this->boundedNullableString(
                OriginalFilename::normalize(is_string($media->sourceFilename) ? $media->sourceFilename : null),
                MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH,
                'original filename',
                $sourceId,
            ),
            'source_kind' => $this->boundedNullableString(
                $media->sourceKind,
                self::MAX_SOURCE_KIND_LENGTH,
                'source kind',
                $sourceId,
            ),
            'source_media_ref' => $this->boundedNullableString(
                $media->sourceMediaKey,
                self::MAX_SOURCE_METADATA_LENGTH,
                'source media reference',
                $sourceId,
            ),
            'source_filename' => $this->boundedNullableString(
                $media->sourceFilename,
                self::MAX_SOURCE_METADATA_LENGTH,
                'source filename',
                $sourceId,
            ),
            'created_at' => $media->createdAt,
            'updated_at' => $media->updatedAt,
        ];
    }

    private function mimeType(mixed $value, string $sourceId): string
    {
        return $this->boundedNullableString(
            $value,
            MediaAsset::MAX_MIME_TYPE_LENGTH,
            'content type',
            $sourceId,
        ) ?? 'application/octet-stream';
    }

    /**
     * @return list<array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null,
     *     path: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>
     */
    private function buildCardMediaPairs(ConnectionInterface $source): array
    {
        $pairs = [];
        $this->importState->skippedUnavailableCardMediaPairs = [];

        foreach ($source->table('study_cards')
            ->where(function ($query): void {
                $query->whereNotNull('promptAudioMediaId')
                    ->orWhereNotNull('answerAudioMediaId')
                    ->orWhereNotNull('imageMediaId');
            })
            ->get(['id', 'userId', 'promptAudioMediaId', 'answerAudioMediaId', 'imageMediaId', 'createdAt', 'updatedAt']) as $card) {
            $this->addCardMediaPairsForSourceCard($pairs, $card);
        }

        return array_values($pairs);
    }

    /**
     * @param  array<string, array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null,
     *     path: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>  $pairs
     */
    private function addCardMediaPairsForSourceCard(array &$pairs, object $card): void
    {
        $targetCard = $this->importState->cardsBySourceId[(string) $card->id];

        foreach ([$card->promptAudioMediaId, $card->answerAudioMediaId, $card->imageMediaId] as $sourceMediaId) {
            $this->addCardMediaPair($pairs, $card, $targetCard, $sourceMediaId);
        }
    }

    /**
     * @param  array<string, array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null,
     *     path: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>  $pairs
     * @param  array{card_id: string, user_id: int, deck_id: string, course_id: string|null}  $targetCard
     */
    private function addCardMediaPair(
        array &$pairs,
        object $card,
        array $targetCard,
        mixed $sourceMediaId,
    ): void {
        if ($sourceMediaId === null || $sourceMediaId === '') {
            return;
        }

        $sourceMediaId = (string) $sourceMediaId;

        if (isset($this->importState->unavailableSourceMediaIds[$sourceMediaId])) {
            $this->importState->skippedUnavailableCardMediaPairs[
                (string) $card->id."\n".$sourceMediaId
            ] = true;

            return;
        }

        $path = $this->importState->pathBySourceMediaId[$sourceMediaId] ?? null;

        if ($path === null) {
            throw new RuntimeException("Missing imported media mapping for [{$sourceMediaId}].");
        }

        if (($this->importState->userIdBySourceMediaId[$sourceMediaId] ?? null) !== $targetCard['user_id']) {
            throw new RuntimeException(
                "Card [{$card->id}] references media [{$sourceMediaId}] owned by another user.",
            );
        }

        $key = $targetCard['card_id']."\n".$path;
        $pairs[$key] = [
            'card_id' => $targetCard['card_id'],
            'user_id' => $targetCard['user_id'],
            'deck_id' => $targetCard['deck_id'],
            'course_id' => $targetCard['course_id'],
            'path' => $path,
            'created_at' => $card->createdAt,
            'updated_at' => $card->updatedAt,
        ];
    }

    private function boundedNullableString(
        mixed $value,
        int $maxLength,
        string $label,
        string $sourceId,
    ): ?string {
        $normalized = is_string($value) && trim($value) !== '' ? trim($value) : null;

        if ($normalized !== null && mb_strlen($normalized) > $maxLength) {
            throw new RuntimeException(
                "Convo Lab media [{$sourceId}] {$label} exceeds {$maxLength} characters.",
            );
        }

        return $normalized;
    }
}
