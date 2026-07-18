<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardAudioValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function missingText(): self
    {
        return new self('The card does not contain text that can be spoken.', 'answer');
    }

    public static function textTooLong(int $maxLength): self
    {
        return new self("answer.answerAudioTextOverride must be {$maxLength} characters or fewer.", 'answer.answerAudioTextOverride');
    }

    public static function invalidVoice(): self
    {
        return new self('answer.answerAudioVoiceId must be a Fish Audio voice ID.', 'answer.answerAudioVoiceId');
    }

    public function field(): string
    {
        return $this->field;
    }
}
