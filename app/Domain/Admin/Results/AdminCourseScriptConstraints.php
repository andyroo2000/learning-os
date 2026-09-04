<?php

namespace App\Domain\Admin\Results;

final readonly class AdminCourseScriptConstraints
{
    /** @param list<string> $speakerVoiceIds */
    public function __construct(
        public string $narratorVoiceId,
        public array $speakerVoiceIds,
        public string $targetLanguage,
        public int $maximumDurationSeconds,
    ) {}
}
