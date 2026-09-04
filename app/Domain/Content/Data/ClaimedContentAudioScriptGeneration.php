<?php

namespace App\Domain\Content\Data;

final readonly class ClaimedContentAudioScriptGeneration
{
    public function __construct(
        public GenerateContentAudioScriptData $data,
        public ContentAudioScriptGenerationAttempt $attempt,
    ) {}
}
