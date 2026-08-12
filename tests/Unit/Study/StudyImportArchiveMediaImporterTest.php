<?php

namespace Tests\Unit\Study;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveMediaCopy;
use App\Domain\Study\Support\StudyImportArchiveMediaImporter;
use LogicException;
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
}
