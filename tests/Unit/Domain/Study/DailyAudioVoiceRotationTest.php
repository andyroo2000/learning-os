<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Results\DailyAudioTrackGenerationResult;
use App\Domain\Study\Support\DailyAudioVoiceRotation;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use PHPUnit\Framework\TestCase;

class DailyAudioVoiceRotationTest extends TestCase
{
    public function test_each_cycle_covers_every_voice_without_adjacent_repeats_and_preserves_script_content(): void
    {
        $narrator = DailyAudioScriptUnit::narration('Listen.', 'english-narrator');
        $units = collect([$narrator]);
        for ($index = 0; $index < 40; $index++) {
            $units->push(DailyAudioScriptUnit::targetLanguage('猫です。', '猫[ねこ]です。', 'A cat.', 'old-voice', 0.9));
            $units->push(DailyAudioScriptUnit::pause(1));
        }
        $result = new DailyAudioTrackGenerationResult(DailyAudioVoiceRotation::apply($units), []);
        $spoken = $result->units->where('type', 'L2')->values();
        $voices = $spoken->pluck('voiceId')->all();

        $this->assertCount(19, StudyCardGenerationDefaults::VOICE_IDS);
        $this->assertEqualsCanonicalizing(StudyCardGenerationDefaults::VOICE_IDS, array_slice($voices, 0, 19));
        $this->assertEqualsCanonicalizing(StudyCardGenerationDefaults::VOICE_IDS, array_slice($voices, 19, 19));
        foreach (array_slice($voices, 1) as $index => $voice) {
            $this->assertNotSame($voices[$index], $voice);
        }
        $this->assertSame($narrator, $result->units->first());
        $this->assertSame('猫です。', $spoken[0]->text);
        $this->assertSame('猫[ねこ]です。', $spoken[0]->reading);
        $this->assertSame('A cat.', $spoken[0]->translation);
        $this->assertSame(0.9, $spoken[0]->speed);
        $this->assertCount(40, $result->units->where('type', 'pause'));
        $this->assertSame($result->scriptUnits(), $result->scriptUnits());
        $this->assertSame($voices[0], $result->scriptUnits()[1]['voiceId']);
    }

    public function test_every_pool_voice_is_valid_and_unique(): void
    {
        $voices = StudyCardGenerationDefaults::VOICE_IDS;
        $this->assertSame($voices, array_values(array_unique($voices)));
        foreach ($voices as $voice) {
            $this->assertSame($voice, StudyCardGenerationDefaults::normalizeVoiceId($voice));
        }
    }

    public function test_constructing_a_result_preserves_previously_assigned_voices(): void
    {
        $units = collect([DailyAudioScriptUnit::targetLanguage('ねこ', null, 'cat', 'saved-voice', 1)]);
        $result = new DailyAudioTrackGenerationResult($units, []);
        $this->assertSame($units, $result->units);
        $this->assertSame('saved-voice', $result->scriptUnits()[0]['voiceId']);
    }
}
