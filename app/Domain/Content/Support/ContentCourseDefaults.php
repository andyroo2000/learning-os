<?php

namespace App\Domain\Content\Support;

final class ContentCourseDefaults
{
    public const NARRATOR_VOICE_EN = 'fishaudio:ac934b39586e475b83f3277cd97b5cd4';

    /** @var array<string, string> */
    private const JOURNEY_REPLACEMENTS = [
        'en-US-Journey-D' => 'en-US-Neural2-J',
        'en-US-Journey-F' => 'en-US-Neural2-F',
    ];

    public static function replaceUnsupportedJourneyVoice(string $voiceId): string
    {
        if (! str_contains($voiceId, 'Journey')) {
            return $voiceId;
        }

        return self::JOURNEY_REPLACEMENTS[$voiceId] ?? self::NARRATOR_VOICE_EN;
    }

    public static function description(string $targetLanguage): string
    {
        return sprintf(
            'Interactive %s audio course with spaced repetition and anticipation drills.',
            strtoupper($targetLanguage),
        );
    }

    public static function voiceProvider(?string $voiceId): string
    {
        return $voiceId !== null && str_starts_with($voiceId, 'fishaudio:')
            ? 'fishaudio'
            : 'google';
    }
}
