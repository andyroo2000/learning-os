<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardDraftValidationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function cardTypeMustMatchCreationKind(): self
    {
        return new self('cardType must match creationKind.', 'cardType');
    }

    public static function imagePromptTooLong(int $maxLength): self
    {
        return new self("imagePrompt must be {$maxLength} characters or fewer.", 'imagePrompt');
    }

    public function field(): string
    {
        return $this->field;
    }
}
