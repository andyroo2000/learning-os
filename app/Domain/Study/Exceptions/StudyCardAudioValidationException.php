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

    public static function invalidUpload(): self
    {
        return new self('The audio must be a valid PCM WAV file.', 'audio');
    }

    public static function uploadTooLarge(int $maxMegabytes): self
    {
        return new self("The audio must not be larger than {$maxMegabytes} MB.", 'audio');
    }

    public static function uploadTooLong(int $maxSeconds): self
    {
        return new self("The audio must not be longer than {$maxSeconds} seconds.", 'audio');
    }

    public static function recognitionCardRequired(): self
    {
        return new self('Captured audio can only be attached to a recognition card.', 'audio');
    }

    public function field(): string
    {
        return $this->field;
    }
}
