<?php

namespace App\Domain\Study\Results;

final class StudyMediaReferenceRepairResult
{
    public function __construct(
        public int $cardsScanned = 0,
        public int $cardsChanged = 0,
        public int $referencesChanged = 0,
        public int $unmatchedReferences = 0,
        public int $ambiguousReferences = 0,
    ) {}

    public function add(self $result): void
    {
        $this->cardsScanned += $result->cardsScanned;
        $this->cardsChanged += $result->cardsChanged;
        $this->referencesChanged += $result->referencesChanged;
        $this->unmatchedReferences += $result->unmatchedReferences;
        $this->ambiguousReferences += $result->ambiguousReferences;
    }
}
