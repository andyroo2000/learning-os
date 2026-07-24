<?php

namespace App\Domain\Study\Exceptions;

use App\Domain\Study\Models\StudyCardDraft;
use RuntimeException;

class StudyCardDraftConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $conflictingUserId = null,
    ) {
        parent::__construct($message);
    }

    public static function idMismatch(StudyCardDraft $draft): self
    {
        return new self(
            'Draft ID already exists with different creation data.',
            $draft->user_id,
        );
    }

    public static function queueFull(): self
    {
        return new self('Draft queue is full. Delete some drafts before adding more.');
    }

    public static function generatingCannotBeEdited(): self
    {
        return new self('Generating drafts cannot be edited yet.');
    }

    public static function generatingCannotCreateCard(): self
    {
        return new self('Generating drafts cannot create cards yet.');
    }

    public static function alreadyCommitted(): self
    {
        return new self('Draft was already committed with a different card ID.');
    }

    public static function committedCannotRetry(): self
    {
        return new self('Committed drafts cannot be retried.');
    }

    public static function onlyErroredDraftsCanRetry(): self
    {
        return new self('Only errored drafts can be retried.');
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== null
            && $this->conflictingUserId !== $userId;
    }
}
