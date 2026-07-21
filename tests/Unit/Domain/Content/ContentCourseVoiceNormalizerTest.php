<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Results\ContentCourseScriptUnit;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentCourseVoiceNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ContentCourseVoiceNormalizerTest extends TestCase
{
    public function test_it_maps_legacy_google_voices_without_changing_valid_fish_voices(): void
    {
        $normalizer = new ContentCourseVoiceNormalizer;
        $narrator = ContentCourseScriptUnit::fromProvider([
            'type' => 'narration_L1', 'text' => 'Listen.', 'voiceId' => 'en-US-Neural2-J',
        ]);
        $speaker = ContentCourseScriptUnit::fromProvider([
            'type' => 'L2', 'text' => '猫', 'reading' => 'ねこ', 'translation' => 'cat',
            'voiceId' => 'ja-JP-Neural2-C', 'speed' => 1,
        ]);

        $this->assertSame(
            ContentCourseDefaults::NARRATOR_VOICE_EN,
            $normalizer->normalize($narrator)->voiceId,
        );
        $this->assertSame(
            'fishaudio:abb4362e736f40b7b5716f4fafcafa9f',
            $normalizer->normalize($speaker)->voiceId,
        );
    }

    public function test_it_rejects_malformed_fish_voice_ids_instead_of_falling_back(): void
    {
        $unit = ContentCourseScriptUnit::fromProvider([
            'type' => 'narration_L1', 'text' => 'Listen.', 'voiceId' => 'fishaudio:unknown',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Course script contains an unsupported speech voice ID.');

        (new ContentCourseVoiceNormalizer)->normalize($unit);
    }
}
