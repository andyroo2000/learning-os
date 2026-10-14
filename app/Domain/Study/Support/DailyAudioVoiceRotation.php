<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use Illuminate\Support\Collection;

final class DailyAudioVoiceRotation
{
    /**
     * One shuffled cycle per track covers every voice before repeating, including
     * across cycle boundaries. Assign only once so synthesis and saved scripts agree.
     *
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @return Collection<int, DailyAudioScriptUnit>
     */
    public static function apply(Collection $units): Collection
    {
        $voices = StudyCardGenerationDefaults::VOICE_IDS;
        shuffle($voices);
        $index = 0;

        return $units->map(static function (DailyAudioScriptUnit $unit) use ($voices, &$index): DailyAudioScriptUnit {
            if ($unit->type !== 'L2') {
                return $unit;
            }

            return DailyAudioScriptUnit::targetLanguage(
                $unit->text,
                $unit->reading,
                $unit->translation,
                $voices[$index++ % count($voices)],
                $unit->speed,
            );
        });
    }
}
