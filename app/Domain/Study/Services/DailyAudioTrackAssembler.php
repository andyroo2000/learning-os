<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Results\DailyAudioTrackAssemblyResult;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Domain\Study\Support\DailyAudioPracticeId;
use App\Support\Audio\AudioTrackAssembler;
use InvalidArgumentException;

class DailyAudioTrackAssembler
{
    public const MAX_SCRIPT_UNITS = AudioTrackAssembler::MAX_SCRIPT_UNITS;

    private readonly AudioTrackAssembler $assembler;

    public function __construct(
        FishAudioSpeechGenerator $speech,
        DailyAudioAudioProcessor $audio,
    ) {
        $this->assembler = new AudioTrackAssembler($speech, $audio);
    }

    /** @param iterable<int, DailyAudioScriptUnit> $scriptUnits */
    public function assemble(
        string $practiceId,
        string $trackId,
        iterable $scriptUnits,
    ): DailyAudioTrackAssemblyResult {
        $practiceId = strtolower(trim($practiceId));
        $trackId = strtolower(trim($trackId));
        if (! DailyAudioPracticeId::isValid($practiceId)
            || ! DailyAudioPracticeId::isValid($trackId)) {
            throw new InvalidArgumentException('Daily Audio assembly requires valid practice and track IDs.');
        }

        $assembled = $this->assembler->assemble(
            $scriptUnits,
            (string) config('daily_audio.disk'),
            DailyAudioPracticeGeneration::storagePath($practiceId, $trackId),
            'learning-os-daily-audio',
            'Daily Audio',
        );

        return new DailyAudioTrackAssemblyResult(
            storagePath: $assembled->storagePath,
            durationSeconds: $assembled->durationSeconds,
            timingData: $assembled->timingData,
            metadata: $assembled->metadata,
        );
    }
}
