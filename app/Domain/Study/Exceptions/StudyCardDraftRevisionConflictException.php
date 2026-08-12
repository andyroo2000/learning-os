<?php

namespace App\Domain\Study\Exceptions;

use App\Domain\Study\Models\StudyCardDraft;
use RuntimeException;

final class StudyCardDraftRevisionConflictException extends RuntimeException
{
    public const CODE = 'draft_revision_conflict';

    public const MESSAGE = 'Study card draft changed since it was loaded.';

    public function __construct(public readonly StudyCardDraft $draft)
    {
        parent::__construct(self::MESSAGE);
    }
}
