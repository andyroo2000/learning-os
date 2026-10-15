<?php

namespace App\Domain\Study\Support;

use App\Support\Audio\FishAudioVoiceNormalizer;

final class StudyCardGenerationDefaults
{
    public const VOICE_ID = 'fishaudio:875668667eb94c20b09856b971d9ca2f';

    public const FEMALE_VOICE_ID = FishAudioVoiceNormalizer::FEMALE_VOICE_ID;

    /** @var list<string> */
    public const VOICE_IDS = [
        'fishaudio:875668667eb94c20b09856b971d9ca2f',
        'fishaudio:72416f3ff95541d9a2456b945e8a7c32',
        'fishaudio:e6e20195abee4187bddfd1a2609a04f9',
        'fishaudio:351aa1e3ef354082bc1f4294d4eea5d0',
        'fishaudio:694e06f2dcc44e4297961d68d6a98313',
        'fishaudio:9639f090aa6346329d7d3aca7e6b7226',
        'fishaudio:21c74cb823fa45ef996ccc91cd6038b6',
        'fishaudio:434dd33d4d3c4cf68b98e3d7f25f31ab',
        'fishaudio:9bf6608381a34f47ada3753f2e9f9381',
        'fishaudio:fce788bf2cbd4698ba32e599f6f0989f',
        'fishaudio:934ad2c65f604b0e84b432a560e3008a',
        'fishaudio:b2d9d8db057042688a5e318b8f405bc2',
        'fishaudio:35c8e5ae5239435f8d9c26c86802b86d',
        'fishaudio:63bc41e652214372b15d9416a30a60b4',
    ];

    private function __construct() {}

    public static function randomVoiceId(): string
    {
        return self::VOICE_IDS[array_rand(self::VOICE_IDS)];
    }

    public static function normalizeVoiceId(string $voiceId): ?string
    {
        return FishAudioVoiceNormalizer::normalize($voiceId);
    }
}
