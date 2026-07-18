<?php

namespace App\Domain\Study\Data;

final readonly class RegenerateStudyCardAnswerAudioData
{
    private function __construct(
        public bool $hasVoiceId,
        public ?string $voiceId,
        public bool $hasTextOverride,
        public ?string $textOverride,
    ) {}

    public static function fromInput(
        bool $hasVoiceId,
        ?string $voiceId,
        bool $hasTextOverride,
        ?string $textOverride,
    ): self {
        return new self(
            hasVoiceId: $hasVoiceId,
            voiceId: $voiceId === null ? null : trim($voiceId),
            hasTextOverride: $hasTextOverride,
            textOverride: $textOverride,
        );
    }
}
