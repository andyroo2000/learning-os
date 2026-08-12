<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyCardDraftRevisionConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use LogicException;

final class StudyCardDraftRevision
{
    private function __construct() {}

    public static function assertExpected(StudyCardDraft $draft, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $draft->revision !== $expectedRevision) {
            throw new StudyCardDraftRevisionConflictException($draft);
        }
    }

    public static function advance(StudyCardDraft $draft): void
    {
        if ($draft->revision >= PHP_INT_MAX) {
            throw new LogicException('Study card draft revision cannot be advanced beyond the platform integer limit.');
        }

        $draft->revision++;
    }
}
