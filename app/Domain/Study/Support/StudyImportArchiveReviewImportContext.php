<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;

final readonly class StudyImportArchiveReviewImportContext
{
    /**
     * @param  array<int, Card>  $importedCardsBySourceCardId
     */
    public function __construct(
        public StudyImportJob $importJob,
        public Deck $deck,
        public array $importedCardsBySourceCardId,
        public Carbon $now,
    ) {}
}
