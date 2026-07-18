<?php

namespace App\Domain\Study\Results;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use Illuminate\Support\Collection;

final readonly class CreateStudyVocabBundleDraftsResult
{
    /** @param Collection<int, StudyCardDraft> $drafts */
    public function __construct(
        public StudyVocabVariantGroup $group,
        public Collection $drafts,
    ) {}
}
