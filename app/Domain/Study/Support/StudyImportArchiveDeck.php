<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveDeck
{
    public function __construct(
        public int $sourceDeckId,
        public string $name,
    ) {}
}
