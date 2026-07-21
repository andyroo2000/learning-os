<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final readonly class CreateContentAudioScriptData
{
    private function __construct(
        public int $userId,
        public string $convoLabUserId,
        public string $sourceText,
        public string $voiceId,
    ) {}

    public static function fromInput(
        int $userId,
        string $convoLabUserId,
        string $sourceText,
        ?string $voiceId,
    ): self {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }

        return new self(
            $userId,
            ConvoLabUserId::normalize($convoLabUserId),
            ContentAudioScriptInput::sourceText($sourceText),
            ContentAudioScriptInput::voiceId($voiceId),
        );
    }
}
