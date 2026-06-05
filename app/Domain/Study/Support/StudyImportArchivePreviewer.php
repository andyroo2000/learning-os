<?php

namespace App\Domain\Study\Support;

use Illuminate\Filesystem\FilesystemAdapter;

final class StudyImportArchivePreviewer
{
    private const MAX_MEDIA_WARNINGS = 10;

    public function __construct(
        private readonly StudyImportArchiveReader $archiveReader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(FilesystemAdapter $disk, string $sourceObjectPath): array
    {
        return $this->previewArchive($this->archiveReader->read($disk, $sourceObjectPath));
    }

    /**
     * @return array<string, mixed>
     */
    public function previewArchive(StudyImportArchiveRead $archive): array
    {
        $mediaReferences = $archive->mediaReferences();
        $mediaPreview = $this->mediaPreview($mediaReferences, $archive->mediaManifestByFilename);

        return [
            'deck_name' => $archive->deckName,
            'card_count' => $archive->cardCount(),
            'note_count' => $archive->noteCount(),
            'review_log_count' => $archive->reviewLogCount(),
            'media_reference_count' => count($mediaReferences),
            'skipped_media_count' => $mediaPreview['skipped_media_count'],
            'warnings' => $mediaPreview['warnings'],
            'note_type_breakdown' => $archive->noteTypeBreakdown(),
        ];
    }

    /**
     * @param  list<string>  $mediaReferences
     * @param  array<string, StudyImportArchiveMediaEntry>  $mediaManifestByFilename
     * @return array{skipped_media_count: int, warnings: list<string>}
     */
    private function mediaPreview(array $mediaReferences, array $mediaManifestByFilename): array
    {
        $referencedFilenames = array_fill_keys($mediaReferences, true);
        $warnings = [];
        $totalWarningCount = 0;

        foreach ($mediaReferences as $filename) {
            $manifestEntry = $mediaManifestByFilename[$filename] ?? null;
            $warning = null;

            if ($manifestEntry === null) {
                $warning = 'Media file "'.$filename.'" is referenced by notes but is missing from the archive manifest.';
            } elseif (! $manifestEntry->hasContent) {
                $warning = 'Media file "'.$filename.'" is listed in the archive manifest but content entry "'.$manifestEntry->sourceMediaRef.'" is missing.';
            }

            if ($warning !== null) {
                $totalWarningCount++;

                if (count($warnings) < self::MAX_MEDIA_WARNINGS) {
                    $warnings[] = $warning;
                }
            }
        }

        $omittedWarningCount = $totalWarningCount - count($warnings);

        if ($omittedWarningCount > 0) {
            $warnings[] = $omittedWarningCount.' additional media '
                .($omittedWarningCount === 1 ? 'warning was' : 'warnings were')
                .' omitted from this preview.';
        }

        $skippedMediaCount = 0;

        foreach (array_keys($mediaManifestByFilename) as $filename) {
            if (! isset($referencedFilenames[$filename])) {
                $skippedMediaCount++;
            }
        }

        return [
            'skipped_media_count' => $skippedMediaCount,
            'warnings' => $warnings,
        ];
    }
}
