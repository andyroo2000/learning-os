<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardImageValidationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function missingPrompt(): self
    {
        return new self('imagePrompt is required.', 'imagePrompt');
    }

    public static function promptTooLong(int $maxLength): self
    {
        return new self("imagePrompt must be {$maxLength} characters or fewer.", 'imagePrompt');
    }

    public static function invalidRole(): self
    {
        return new self('imageRole must be prompt, answer, or both.', 'imageRole');
    }

    public function field(): string
    {
        return $this->field;
    }
}
