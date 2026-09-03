<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use InvalidArgumentException;

final class StudyImportArchiveMediaBudget
{
    private int $consumedBytes = 0;

    public function __construct(
        private readonly StudyImportArchiveExpansionPolicy $expansionPolicy,
    ) {}

    public function tryConsume(StudyImportArchiveMediaSource $source): bool
    {
        try {
            $this->add($source);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function consumeForManifest(StudyImportArchiveMediaSource $source): void
    {
        try {
            $this->add($source);
        } catch (InvalidArgumentException) {
            throw StudyImportPreviewException::mediaExpansionTooLarge(
                $this->expansionPolicy->maxTotalMediaBytes(),
            );
        }
    }

    private function add(StudyImportArchiveMediaSource $source): void
    {
        $this->consumedBytes = $this->expansionPolicy->addMediaBytes(
            $this->consumedBytes,
            $source->declaredSize,
        );
    }
}
