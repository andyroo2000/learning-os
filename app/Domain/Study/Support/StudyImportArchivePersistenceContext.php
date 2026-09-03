<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;

final readonly class StudyImportArchivePersistenceContext
{
    /**
     * @param  list<StudyImportArchiveCard>  $importableCards
     */
    public function __construct(
        public StudyImportJob $importJob,
        public StudyImportArchiveRead $archive,
        public Carbon $now,
        public array $importableCards,
    ) {}
}
