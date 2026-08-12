<?php

namespace Tests\Unit\Study;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveMediaCopy;
use App\Domain\Study\Support\StudyImportArchiveMediaEntry;
use App\Domain\Study\Support\StudyImportArchiveMediaImporter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StudyImportArchiveMediaImporterTest extends TestCase
{
    public function test_media_record_persistence_requires_the_parent_import_transaction(): void
    {
        $importJob = new StudyImportJob;
        $importJob->id = '01kzsz0vrhx6gaak37ptj029t2';
        $importJob->user_id = 1;
        $mediaImporter = app(StudyImportArchiveMediaImporter::class);
        $mediaCopy = new StudyImportArchiveMediaCopy([], 0);

        foreach ([
            fn () => $mediaImporter->createMediaAssets($importJob, $mediaCopy, now()),
            fn () => $mediaImporter->attachToCards($importJob->user_id, new Deck, [], [], now()),
        ] as $persistMedia) {
            try {
                $persistMedia();
                $this->fail('Expected import media persistence to require the parent transaction.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Study import media records must be persisted inside the parent import transaction.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_cleanup_reports_failed_deletions_without_hiding_later_cleanup_work(): void
    {
        Exceptions::fake();
        $transportFailure = new RuntimeException('Storage transport failed.');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('study/imports/job/false.mp3')->andReturnFalse();
        $disk->shouldReceive('delete')->once()->with('study/imports/job/throw.mp3')->andThrow($transportFailure);
        $disk->shouldReceive('delete')->once()->with('study/imports/job/success.mp3')->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);
        $entry = new StudyImportArchiveMediaEntry('0', 'word.mp3', true, 5, hash('sha256', 'audio'));
        $copy = new StudyImportArchiveMediaCopy([
            ['entry' => $entry, 'filename' => 'false.mp3', 'path' => 'study/imports/job/false.mp3'],
            ['entry' => $entry, 'filename' => 'throw.mp3', 'path' => 'study/imports/job/throw.mp3'],
            ['entry' => $entry, 'filename' => 'success.mp3', 'path' => 'study/imports/job/success.mp3'],
        ], 0);

        app(StudyImportArchiveMediaImporter::class)->deleteCopiedMedia($copy);

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage()
                === 'Unable to remove a copied study import media object: study/imports/job/false.mp3',
        );
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $transportFailure);
        Exceptions::assertReportedCount(2);
    }
}
