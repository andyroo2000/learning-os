<?php

namespace App\Domain\Study\Support;

final class StudyCardGenerationDefaults
{
    public const VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    public const FEMALE_VOICE_ID = 'fishaudio:9639f090aa6346329d7d3aca7e6b7226';

    private function __construct() {}

    public static function normalizeVoiceId(string $voiceId): ?string
    {
        $voiceId = trim($voiceId);

        if (preg_match('/^fishaudio:[a-f0-9]{32}$/i', $voiceId) === 1) {
            return $voiceId;
        }

        if (preg_match('/^ja-JP-(?:Neural2|Wavenet)-([A-D])$/i', $voiceId, $matches) === 1) {
            return in_array(strtoupper($matches[1]), ['A', 'B'], true)
                ? self::FEMALE_VOICE_ID
                : self::VOICE_ID;
        }

        if ($voiceId === 'Takumi') {
            return self::VOICE_ID;
        }

        if (in_array($voiceId, ['Kazuha', 'Tomoko'], true)) {
            return self::FEMALE_VOICE_ID;
        }

        return null;
    }
}
