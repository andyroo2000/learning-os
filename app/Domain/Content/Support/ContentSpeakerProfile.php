<?php

namespace App\Domain\Content\Support;

final class ContentSpeakerProfile
{
    /** @var array<string, string> */
    private const FISH_AUDIO_GENDERS = [
        '0dff3f6860294829b98f8c4501b2cf25' => 'male',
        '875668667eb94c20b09856b971d9ca2f' => 'male',
        'abb4362e736f40b7b5716f4fafcafa9f' => 'male',
        'b3e9710c629a472f8224e1c4975a869e' => 'male',
        '72416f3ff95541d9a2456b945e8a7c32' => 'female',
        'e6e20195abee4187bddfd1a2609a04f9' => 'female',
        '351aa1e3ef354082bc1f4294d4eea5d0' => 'female',
        '694e06f2dcc44e4297961d68d6a98313' => 'female',
        '9639f090aa6346329d7d3aca7e6b7226' => 'female',
    ];

    /** @var array<string, string> */
    private const POLLY_GENDERS = [
        'Takumi' => 'male',
        'Kazuha' => 'female',
        'Tomoko' => 'female',
    ];

    public static function provider(string $voiceId): ?string
    {
        if (str_starts_with($voiceId, 'fishaudio:')) {
            return 'fishaudio';
        }
        if (array_key_exists($voiceId, self::POLLY_GENDERS)) {
            return 'polly';
        }
        if (preg_match('/^[a-z]{2}-[A-Z]{2}-/', $voiceId) === 1) {
            return 'google';
        }

        return null;
    }

    public static function gender(string $voiceId): ?string
    {
        if (str_starts_with($voiceId, 'fishaudio:')) {
            return self::FISH_AUDIO_GENDERS[substr($voiceId, 10)] ?? null;
        }
        if (isset(self::POLLY_GENDERS[$voiceId])) {
            return self::POLLY_GENDERS[$voiceId];
        }
        if (preg_match('/^ja-JP-(?:Wavenet|Neural2)-([A-D])$/', $voiceId, $matches) === 1) {
            return in_array($matches[1], ['A', 'B'], true) ? 'female' : 'male';
        }

        return null;
    }

    public static function avatarUrl(string $targetLanguage, ?string $gender, string $tone): ?string
    {
        if ($gender === null || ! in_array($targetLanguage, ['ja', 'en'], true)) {
            return null;
        }

        return "/api/avatars/{$targetLanguage}-{$gender}-{$tone}.jpg";
    }
}
