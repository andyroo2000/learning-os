<?php

namespace App\Domain\Study\Support;

use App\Support\Audio\FishAudioVoiceNormalizer;

final class StudyCardGenerationDefaults
{
    public const VOICE_ID = FishAudioVoiceNormalizer::DEFAULT_VOICE_ID;

    public const FEMALE_VOICE_ID = FishAudioVoiceNormalizer::FEMALE_VOICE_ID;

    private function __construct() {}

    public static function normalizeVoiceId(string $voiceId): ?string
    {
        return FishAudioVoiceNormalizer::normalize($voiceId);
    }
}
