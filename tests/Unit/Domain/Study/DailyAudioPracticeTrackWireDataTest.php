<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Support\DailyAudioPracticeTrackWireData;
use PHPUnit\Framework\TestCase;

class DailyAudioPracticeTrackWireDataTest extends TestCase
{
    public function test_it_normalizes_legacy_script_units_and_supplies_spoken_voice_defaults(): void
    {
        $units = DailyAudioPracticeTrackWireData::scriptUnits([
            ['type' => 'marker', 'label' => 'Review'],
            ['kind' => 'native_language', 'text' => 'company'],
            ['kind' => 'target_language', 'text' => '会社', 'reading' => 'かいしゃ'],
            ['type' => 'L2', 'text' => '猫', 'voiceId' => 'voice-1', 'speed' => 0.9],
        ]);

        $this->assertSame([
            ['type' => 'marker', 'label' => 'Review'],
            ['type' => 'narration_L1', 'text' => 'company', 'voiceId' => ''],
            ['type' => 'L2', 'text' => '会社', 'reading' => 'かいしゃ', 'voiceId' => ''],
            ['type' => 'L2', 'text' => '猫', 'voiceId' => 'voice-1', 'speed' => 0.9],
        ], $units);
    }

    public function test_it_aligns_legacy_timings_to_non_marker_units_and_preserves_canonical_timings(): void
    {
        $units = DailyAudioPracticeTrackWireData::scriptUnits([
            ['type' => 'marker', 'label' => 'Review'],
            ['kind' => 'native_language', 'text' => 'company'],
            ['type' => 'pause', 'seconds' => 0.5],
            ['kind' => 'target_language', 'text' => '会社'],
        ]);

        $this->assertSame([
            ['unitIndex' => 1, 'startTime' => 0, 'endTime' => 600],
            ['unitIndex' => 2, 'startTime' => 600, 'endTime' => 1200],
            ['unitIndex' => 3, 'startTime' => 1200, 'endTime' => 1800],
            ['unitIndex' => 3, 'startTime' => 1800, 'endTime' => 2400],
        ], DailyAudioPracticeTrackWireData::timingData([
            ['startMs' => 0, 'endMs' => 600],
            ['startMs' => 600, 'endMs' => 1200],
            ['startMs' => 1200, 'endMs' => 1800],
            ['unitIndex' => 3, 'startTime' => 1800, 'endTime' => 2400],
        ], $units));
    }

    public function test_it_omits_unalignable_legacy_timings_instead_of_emitting_an_ambiguous_contract(): void
    {
        $this->assertNull(DailyAudioPracticeTrackWireData::timingData([
            ['startMs' => 0, 'endMs' => 1200],
        ], null));
    }

    public function test_nullable_payloads_remain_nullable(): void
    {
        $this->assertNull(DailyAudioPracticeTrackWireData::scriptUnits(null));
        $this->assertNull(DailyAudioPracticeTrackWireData::timingData(null, null));
    }
}
