<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\OriginalFilename;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

final class StudyImportArchiveMediaImporter
{
    public function __construct(
        private readonly RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        private readonly RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
        private readonly StudyImportArchiveReader $archiveReader,
    ) {}

    /**
     * Copy referenced media before the database transaction begins.
     *
     * @param  list<StudyImportArchiveCard>  $importableCards
     */
    public function copy(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $importableCards): StudyImportArchiveMediaCopy
    {
        $referencedFilenames = $this->referencedMediaFilenames($importableCards);
        $targets = $this->mediaTargets($importJob, $archive, $referencedFilenames);

        if ($targets === []) {
            return new StudyImportArchiveMediaCopy([], count($referencedFilenames));
        }

        $targetPathsBySourceMediaRef = [];

        foreach ($targets as $sourceMediaRef => $target) {
            $targetPathsBySourceMediaRef[$sourceMediaRef] = $target['path'];
        }

        try {
            $copiedBySourceMediaRef = $this->archiveReader->copyMediaEntriesToDisk(
                Storage::disk('study-imports'),
                (string) $importJob->source_object_path,
                Storage::disk(MediaAsset::DISK_MEDIA),
                $targetPathsBySourceMediaRef,
            );
            $copiedTargets = [];

            foreach ($targets as $sourceMediaRef => $target) {
                if (($copiedBySourceMediaRef[$sourceMediaRef] ?? false) === true) {
                    $copiedTargets[$sourceMediaRef] = $target;

                    continue;
                }

                // A failed streamed write can leave a partial object behind, depending on the storage adapter.
                if (! Storage::disk(MediaAsset::DISK_MEDIA)->delete($target['path'])) {
                    throw new RuntimeException('Unable to remove a partial study import media object.');
                }
            }

            return new StudyImportArchiveMediaCopy(
                $copiedTargets,
                count($referencedFilenames) - count($copiedTargets),
            );
        } catch (Throwable $exception) {
            $this->deleteTargets($targets);

            throw $exception;
        }
    }

    /**
     * Persist copied-media metadata inside the parent import transaction.
     *
     * @return array<string, MediaAsset>
     */
    public function createMediaAssets(StudyImportJob $importJob, StudyImportArchiveMediaCopy $copy, Carbon $now): array
    {
        $this->assertParentImportTransaction();
        $mediaAssetsByFilename = [];

        foreach ($copy->targets as $target) {
            $entry = $target['entry'];
            $sourceFilename = $this->normalizedSourceFilename($entry);

            if ($sourceFilename === null) {
                continue;
            }

            $mediaAsset = new MediaAsset([
                'user_id' => $importJob->user_id,
                'disk' => MediaAsset::DISK_MEDIA,
                'path' => $target['path'],
                'mime_type' => $this->mimeTypeForFilename($sourceFilename),
                'size_bytes' => $entry->sizeBytes,
                'checksum_sha256' => $entry->checksumSha256,
                'original_filename' => $sourceFilename,
            ]);
            $mediaAsset->public_url = null;
            $mediaAsset->import_job_id = $importJob->id;
            $mediaAsset->source_kind = StudyImportJob::SOURCE_TYPE_ANKI_COLPKG;
            $mediaAsset->source_media_ref = $entry->sourceMediaRef;
            $mediaAsset->source_filename = $sourceFilename;
            $mediaAsset->created_at = $now;
            $mediaAsset->updated_at = $now;
            $mediaAsset->saveOrFail();

            $this->recordMediaAssetSyncFeedEntry->handle(
                userId: $importJob->user_id,
                operation: SyncFeedOperation::Create,
                mediaAsset: $mediaAsset,
            );

            $mediaAssetsByFilename[$target['filename']] = $mediaAsset;
        }

        return $mediaAssetsByFilename;
    }

    /**
     * @param  list<array{card: Card, archive_card: StudyImportArchiveCard}>  $importedCards
     * @param  array<string, MediaAsset>  $mediaAssetsByFilename
     */
    public function attachToCards(int $userId, Deck $deck, array $importedCards, array $mediaAssetsByFilename, Carbon $now): void
    {
        $this->assertParentImportTransaction();

        foreach ($importedCards as $importedCard) {
            $card = $importedCard['card'];
            $card->setRelation('deck', $deck);

            foreach ($importedCard['archive_card']->mediaReferences() as $filename) {
                $mediaAsset = $mediaAssetsByFilename[$filename] ?? null;

                if ($mediaAsset === null) {
                    continue;
                }

                $changes = $card->mediaAssets()->syncWithoutDetaching([$mediaAsset->id]);

                if ($changes['attached'] === []) {
                    continue;
                }

                $card->updated_at = $now;
                $card->saveOrFail();
                $pivot = DB::table('card_media')
                    ->where('card_id', $card->id)
                    ->where('media_asset_id', $mediaAsset->id)
                    ->first(['created_at', 'updated_at']);

                $this->recordCardMediaSyncFeedEntry->handle(
                    userId: $userId,
                    operation: SyncFeedOperation::Create,
                    cardId: $card->id,
                    mediaAssetId: $mediaAsset->id,
                    deckId: $card->deck_id,
                    courseId: $card->deckCourseId(),
                    createdAt: $pivot?->created_at,
                    updatedAt: $pivot?->updated_at,
                );
            }
        }
    }

    public function deleteCopiedMedia(StudyImportArchiveMediaCopy $copy): void
    {
        $this->deleteTargets($copy->targets);
    }

    /**
     * @param  list<string>  $referencedFilenames
     * @return array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>
     */
    private function mediaTargets(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $referencedFilenames): array
    {
        $targets = [];

        foreach ($referencedFilenames as $filename) {
            $entry = $archive->mediaManifestByFilename[$filename] ?? null;

            if (! $this->isImportableMediaEntry($entry)) {
                continue;
            }

            $path = $this->mediaStoragePath($importJob, $entry);

            if ($path === null) {
                continue;
            }

            $targets[$entry->sourceMediaRef] = [
                'entry' => $entry,
                'filename' => $filename,
                'path' => $path,
            ];
        }

        return $targets;
    }

    /**
     * @param  list<StudyImportArchiveCard>  $importableCards
     * @return list<string>
     */
    private function referencedMediaFilenames(array $importableCards): array
    {
        $filenames = [];

        foreach ($importableCards as $archiveCard) {
            foreach ($archiveCard->mediaReferences() as $filename) {
                $filenames[$filename] = true;
            }
        }

        return array_keys($filenames);
    }

    private function isImportableMediaEntry(?StudyImportArchiveMediaEntry $entry): bool
    {
        return $entry !== null
            && $entry->hasContent
            && $entry->sizeBytes !== null
            && $entry->sizeBytes >= 1
            && $entry->sizeBytes <= MediaAsset::MAX_JSON_SAFE_SIZE_BYTES
            && $entry->checksumSha256 !== null
            && strlen($entry->checksumSha256) === 64
            && ctype_xdigit($entry->checksumSha256)
            && mb_strlen($entry->sourceMediaRef) <= MediaAsset::MAX_PATH_LENGTH
            && $this->normalizedSourceFilename($entry) !== null;
    }

    private function mediaStoragePath(StudyImportJob $importJob, StudyImportArchiveMediaEntry $entry): ?string
    {
        $filename = $this->normalizedSourceFilename($entry);

        if ($filename === null) {
            return null;
        }

        $prefix = 'study/imports/'.$importJob->id.'/'.$this->pathSegment($entry->sourceMediaRef).'-';
        $availableFilenameLength = MediaAsset::MAX_PATH_LENGTH - mb_strlen($prefix);

        if ($availableFilenameLength < 1) {
            return null;
        }

        return $prefix.$this->limitFilename($filename, $availableFilenameLength);
    }

    private function normalizedSourceFilename(StudyImportArchiveMediaEntry $entry): ?string
    {
        $filename = OriginalFilename::normalize($entry->sourceFilename);

        return $filename === null || mb_strlen($filename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH
            ? null
            : $filename;
    }

    private function pathSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        $segment = trim($segment, '.-');

        if ($segment === '') {
            $segment = 'media';
        }

        if (mb_strlen($segment) <= 64) {
            return $segment;
        }

        return mb_substr($segment, 0, 51).'-'.substr(hash('sha256', $value), 0, 12);
    }

    private function limitFilename(string $filename, int $maxLength): string
    {
        if (mb_strlen($filename) <= $maxLength) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '') {
            return mb_substr($filename, 0, $maxLength);
        }

        $suffix = '.'.$extension;
        $basenameMaxLength = max(1, $maxLength - mb_strlen($suffix));

        return mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, $basenameMaxLength).$suffix;
    }

    private function mimeTypeForFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'aac' => 'audio/aac',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'flac' => 'audio/flac',
            'gif' => 'image/gif',
            'jpeg', 'jpg' => 'image/jpeg',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'oga', 'ogg' => 'audio/ogg',
            'ogv' => 'video/ogg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'wav' => 'audio/wav',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>  $targets
     */
    private function deleteTargets(array $targets): void
    {
        foreach ($targets as $target) {
            Storage::disk(MediaAsset::DISK_MEDIA)->delete($target['path']);
        }
    }

    private function assertParentImportTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Study import media records must be persisted inside the parent import transaction.');
        }
    }
}
