<?php

namespace Tests\Feature\Study;

use Tests\Feature\Study\Concerns\PreviewsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchiveMediaSelectionTest extends TestCase
{
    use PreviewsStudyImportArchives;

    public function test_it_does_not_count_unreferenced_archive_media_as_skipped(): void
    {
        $this->assertMediaPreview(
            'unused-media.colpkg',
            [
                'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'company',
                'media_map' => [
                    '0' => 'word.mp3',
                    '1' => 'unused.png',
                ],
            ],
            [
                'media_reference_count' => 1,
                'skipped_media_count' => 0,
                'warnings' => [],
            ],
        );
    }
}
