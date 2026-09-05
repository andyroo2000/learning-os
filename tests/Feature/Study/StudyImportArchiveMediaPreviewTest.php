<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use Tests\Feature\Study\Concerns\PreviewsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchiveMediaPreviewTest extends TestCase
{
    use PreviewsStudyImportArchives;

    public function test_it_previews_quoted_image_media_references_with_spaces(): void
    {
        $this->assertMediaPreview(
            'spaced-image-filename.colpkg',
            [
                'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'<img src="native image.png"> company',
                'media_map' => [
                    '0' => 'word.mp3',
                    '1' => 'native image.png',
                ],
            ],
            [
                'media_reference_count' => 2,
                'skipped_media_count' => 0,
                'warnings' => [],
            ],
        );
    }

    public function test_it_reports_missing_and_skipped_media_manifest_entries(): void
    {
        $this->assertMediaPreview(
            'media-gaps.colpkg',
            [
                'media_map' => [
                    '0' => 'word.mp3',
                    '2' => 'unused.mp3',
                ],
            ],
            [
                'media_reference_count' => 2,
                'skipped_media_count' => 1,
                'warnings' => ['Media file "company.png" is referenced by notes but is missing from the archive manifest.'],
            ],
        );
    }

    public function test_it_reports_manifest_entries_without_archive_content(): void
    {
        $this->assertMediaPreview(
            'missing-media-content.colpkg',
            [
                'media_entries' => [
                    '0' => 'audio-bytes',
                ],
            ],
            [
                'media_reference_count' => 2,
                'skipped_media_count' => 1,
                'warnings' => ['Media file "company.png" is listed in the archive manifest but content entry "1" is missing.'],
            ],
        );
    }

    public function test_it_reports_referenced_media_with_invalid_metadata_as_skipped(): void
    {
        $this->assertMediaPreview(
            'empty-media.colpkg',
            [
                'media_entries' => [
                    '0' => '',
                    '1' => 'image-bytes',
                ],
            ],
            [
                'skipped_media_count' => 1,
                'warnings' => ['Media file "word.mp3" has unsupported or invalid archive metadata and will be skipped.'],
            ],
        );
    }

    public function test_it_rejects_invalid_media_manifest_json(): void
    {
        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded media manifest could not be parsed.');

        $this->previewArchive('invalid-media-json.colpkg', ['media_contents' => '{']);
    }

    public function test_it_caps_media_warnings_with_a_summary(): void
    {
        $fields = implode('', array_map(
            static fn (int $index): string => '<img src="missing-'.$index.'.png">',
            range(1, 12),
        ));

        $preview = $this->previewArchive(
            'many-media-gaps.colpkg',
            [
                'media_map' => [],
                'note_one_fields' => $fields,
            ],
        );

        $this->assertSame(12, $preview['media_reference_count']);
        $this->assertCount(11, $preview['warnings']);
        $this->assertSame('Media file "missing-1.png" is referenced by notes but is missing from the archive manifest.', $preview['warnings'][0]);
        $this->assertSame('Media file "missing-10.png" is referenced by notes but is missing from the archive manifest.', $preview['warnings'][9]);
        $this->assertSame('2 additional media warnings were omitted from this preview.', $preview['warnings'][10]);
    }

    public function test_it_skips_media_manifest_filenames_containing_nul_bytes(): void
    {
        $preview = $this->previewArchive(
            'nul-media-filename.colpkg',
            [
                'media_map' => [
                    '0' => "word\0.mp3",
                    '1' => 'company.png',
                ],
            ],
        );

        $this->assertSame([
            'Media file "word.mp3" is referenced by notes but is missing from the archive manifest.',
        ], $preview['warnings']);
    }
}
