<?php

namespace App\Domain\Content\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateContentEpisodeData
{
    private const JLPT_LEVELS = ['N5', 'N4', 'N3', 'N2', 'N1'];

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
        $convoLabUserId = strtolower(trim($convoLabUserId));
        $title = trim($title);
        $sourceText = trim($sourceText);
        $audioSpeed = trim($audioSpeed);

        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }
        if (! Str::isUuid($convoLabUserId)) {
            throw new InvalidArgumentException('Convo Lab user ID must be a UUID.');
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
        if ($audioSpeed === '' || mb_strlen($audioSpeed) > 32) {
            throw new InvalidArgumentException('Episode audio speed must contain at most 32 characters.');
        }
        if ($jlptLevel !== null && ! in_array($jlptLevel, self::JLPT_LEVELS, true)) {
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
