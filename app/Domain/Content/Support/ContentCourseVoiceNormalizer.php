<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Results\ContentCourseScriptUnit;
use App\Support\Audio\FishAudioVoiceNormalizer;
use InvalidArgumentException;

final class ContentCourseVoiceNormalizer
{
    private const LEGACY_NARRATOR_VOICES = [
        'en-US-Neural2-F',
        'en-US-Neural2-J',
        'en-US-Wavenet-F',
        'en-US-Wavenet-J',
        'en-US-Journey-D',
        'en-US-Journey-F',
    ];

    public function normalize(ContentCourseScriptUnit $unit): ContentCourseScriptUnit
    {
        if ($unit->voiceId === null) {
            return $unit;
        }

        $voiceId = $unit->type === 'narration_L1'
            ? $this->narrator($unit->voiceId)
            : FishAudioVoiceNormalizer::normalize($unit->voiceId);
        if ($voiceId === null) {
            throw new InvalidArgumentException('Course script contains an unsupported speech voice ID.');
        }

        return $unit->withVoiceId($voiceId);
    }

    private function narrator(string $voiceId): string
    {
        $voiceId = trim($voiceId);
        if (preg_match('/^fishaudio:[a-f0-9]{32}$/i', $voiceId) === 1) {
            return $voiceId;
        }
        if (! in_array($voiceId, self::LEGACY_NARRATOR_VOICES, true)) {
            throw new InvalidArgumentException('Course script contains an unsupported speech voice ID.');
        }

        return ContentCourseDefaults::NARRATOR_VOICE_EN;
    }
}
