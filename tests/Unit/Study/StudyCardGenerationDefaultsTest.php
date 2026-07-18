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
    public function test_it_migrates_supported_legacy_google_voices_to_the_fish_default(string $voiceId): void
    {
        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            StudyCardGenerationDefaults::normalizeVoiceId($voiceId),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyGoogleVoiceProvider(): iterable
    {
        yield 'Neural2 lower boundary' => ['ja-JP-Neural2-A'];
        yield 'Neural2 upper boundary' => ['ja-JP-Neural2-D'];
        yield 'Wavenet lower boundary' => ['ja-JP-Wavenet-A'];
        yield 'Wavenet upper boundary' => ['ja-JP-Wavenet-D'];
        yield 'case insensitive' => ['JA-jp-wAVEnet-C'];
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
        yield 'malformed Fish identifier' => ['fishaudio:not-a-voice'];
    }
}
