<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentEpisodeInput;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final readonly class CreateContentEpisodeData
{
    private function __construct(
        public int $userId,
        public string $convoLabUserId,
        public string $title,
        public string $sourceText,
        public string $targetLanguage,
        public string $nativeLanguage,
        public string $audioSpeed,
        public ?string $jlptLevel,
        public bool $autoGenerateAudio,
    ) {}

    public static function fromInput(
        int $userId,
        string $convoLabUserId,
        string $title,
        string $sourceText,
        string $targetLanguage,
        string $nativeLanguage,
        string $audioSpeed = 'medium',
        ?string $jlptLevel = null,
        bool $autoGenerateAudio = true,
    ): self {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $title = trim($title);
        $sourceText = trim($sourceText);
        $audioSpeed = trim($audioSpeed);

        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Episode title must contain at most 255 characters.');
        }
        if ($sourceText === '') {
            throw new InvalidArgumentException('Episode source text is required.');
        }
        if ($targetLanguage !== 'ja') {
            throw new InvalidArgumentException('Episode target language must be Japanese.');
        }
        if ($nativeLanguage !== 'en') {
            throw new InvalidArgumentException('Episode native language must be English.');
        }
        if (! in_array($audioSpeed, ContentEpisodeInput::AUDIO_SPEEDS, true)) {
            throw new InvalidArgumentException('Episode audio speed is invalid.');
        }
        if ($jlptLevel !== null && ! in_array($jlptLevel, ContentEpisodeInput::JLPT_LEVELS, true)) {
            throw new InvalidArgumentException('Episode JLPT level is invalid.');
        }

        return new self(
            $userId,
            $convoLabUserId,
            $title,
            $sourceText,
            $targetLanguage,
            $nativeLanguage,
            $audioSpeed,
            $jlptLevel,
            $autoGenerateAudio,
        );
    }
}
