<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardGenerationDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyCardGenerationDefaultsTest extends TestCase
{
    public function test_it_preserves_fish_voice_ids_and_trims_whitespace(): void
    {
        $voiceId = 'fishaudio:875668667eb94c20b09856b971d9ca2f';

        $this->assertSame(
            $voiceId,
            StudyCardGenerationDefaults::normalizeVoiceId(" \t{$voiceId}\n"),
        );
    }

    #[DataProvider('legacyGoogleVoiceProvider')]
    public function test_it_migrates_supported_legacy_google_voices_to_a_fish_voice_with_matching_gender(
        string $voiceId,
        string $expectedVoiceId,
    ): void {
        $this->assertSame(
            $expectedVoiceId,
            StudyCardGenerationDefaults::normalizeVoiceId($voiceId),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyGoogleVoiceProvider(): iterable
    {
        yield 'Neural2 female A' => ['ja-JP-Neural2-A', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
        yield 'Neural2 female B' => ['ja-JP-Neural2-B', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
        yield 'Neural2 male C' => ['ja-JP-Neural2-C', StudyCardGenerationDefaults::VOICE_ID];
        yield 'Neural2 male D' => ['ja-JP-Neural2-D', StudyCardGenerationDefaults::VOICE_ID];
        yield 'Wavenet female A' => ['ja-JP-Wavenet-A', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
        yield 'Wavenet female B' => ['ja-JP-Wavenet-B', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
        yield 'Wavenet male C with case-insensitive input' => ['JA-jp-wAVEnet-C', StudyCardGenerationDefaults::VOICE_ID];
        yield 'Wavenet male D' => ['ja-JP-Wavenet-D', StudyCardGenerationDefaults::VOICE_ID];
    }

    #[DataProvider('legacyPollyVoiceProvider')]
    public function test_it_migrates_supported_legacy_polly_voices_to_a_fish_voice_with_matching_gender(
        string $voiceId,
        string $expectedVoiceId,
    ): void {
        $this->assertSame(
            $expectedVoiceId,
            StudyCardGenerationDefaults::normalizeVoiceId($voiceId),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyPollyVoiceProvider(): iterable
    {
        yield 'Takumi' => ['Takumi', StudyCardGenerationDefaults::VOICE_ID];
        yield 'Kazuha' => ['Kazuha', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
        yield 'Tomoko' => ['Tomoko', StudyCardGenerationDefaults::FEMALE_VOICE_ID];
    }

    #[DataProvider('unsupportedVoiceProvider')]
    public function test_it_rejects_unsupported_voice_ids(string $voiceId): void
    {
        $this->assertNull(StudyCardGenerationDefaults::normalizeVoiceId($voiceId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedVoiceProvider(): iterable
    {
        yield 'blank' => [' '];
        yield 'unknown provider' => ['google:ja-JP-Wavenet-D'];
        yield 'unsupported Google family' => ['ja-JP-Standard-A'];
        yield 'unsupported Google variant' => ['ja-JP-Wavenet-E'];
        yield 'non-canonical Polly casing' => ['takumi'];
        yield 'malformed Fish identifier' => ['fishaudio:not-a-voice'];
    }
}
