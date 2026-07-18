<?php

namespace App\Domain\Study\Support;

final class StudyCardGenerationDefaults
{
    public const VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    private function __construct() {}

    public static function normalizeVoiceId(string $voiceId): ?string
    {
        $voiceId = trim($voiceId);

        if (preg_match('/^fishaudio:[a-f0-9]{32}$/i', $voiceId) === 1) {
            return $voiceId;
        }

        if (preg_match('/^ja-JP-(?:Neural2|Wavenet)-[A-D]$/i', $voiceId) === 1
            || in_array($voiceId, ['Takumi', 'Kazuha', 'Tomoko'], true)) {
            return self::VOICE_ID;
        }

        return null;
    }
}
