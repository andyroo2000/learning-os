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

    public static function invalidPayloads(): self
    {
        return new self('study card payloads contain invalid content.', 'payloads');
    }

    public static function payloadsTooLarge(int $maxKilobytes): self
    {
        return new self("study card payloads must be {$maxKilobytes} KB or smaller.", 'payloads');
    }

    public static function promptTooDeep(int $maxDepth): self
    {
        return new self("prompt must be {$maxDepth} levels deep or fewer.", 'prompt');
    }

    public static function answerTooDeep(int $maxDepth): self
    {
        return new self("answer must be {$maxDepth} levels deep or fewer.", 'answer');
    }

    public function field(): string
    {
        return $this->field;
    }
}
