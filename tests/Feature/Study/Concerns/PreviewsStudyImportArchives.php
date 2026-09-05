<?php

namespace Tests\Feature\Study\Concerns;

use App\Domain\Study\Support\StudyImportArchivePreviewer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;

trait PreviewsStudyImportArchives
{
    use BuildsStudyImportArchives;

    /**
     * @param  array<string, mixed>  $archiveOptions
     * @return array<string, mixed>
     */
    private function previewArchive(string $filename, array $archiveOptions): array
    {
        Storage::fake('study-imports');
        $path = 'study/imports/preview/'.$filename;
        Storage::disk('study-imports')->put(
            $path,
            $this->buildStudyImportArchiveBytes($archiveOptions),
        );

        return app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            $path,
        );
    }

    /**
     * @param  array<string, mixed>  $archiveOptions
     * @param  array<string, mixed>  $expected
     */
    private function assertMediaPreview(
        string $filename,
        array $archiveOptions,
        array $expected,
    ): void {
        $preview = $this->previewArchive($filename, $archiveOptions);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $preview[$key]);
        }
    }
}
