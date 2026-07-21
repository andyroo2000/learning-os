<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Results\ContentAudioScriptRenderResult;
use App\Domain\Content\Results\ContentDialogueAudioScriptUnit;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\FishAudioVoiceNormalizer;
use InvalidArgumentException;
use RuntimeException;

final readonly class ContentAudioScriptRenderAssembler
{
    public const SEGMENT_PAUSE_SECONDS = 0.35;

    public function __construct(private AudioTrackAssembler $assembler) {}

    public function assemble(
        ContentAudioScript $script,
        int $attempt,
        string $speed,
        float $numericSpeed,
    ): ContentAudioScriptRenderResult {
        $voiceId = FishAudioVoiceNormalizer::normalize((string) $script->voice_id);
        if ($voiceId === null) {
            throw new InvalidArgumentException('Script contains an unsupported speech voice ID.');
        }

        $segments = $script->segments->values();
        if ($segments->isEmpty()) {
            throw new InvalidArgumentException('Review script segments before generating audio.');
        }

        $units = [];
        foreach ($segments as $index => $segment) {
            $metadata = $segment->metadata;
            $kana = is_array($metadata) ? data_get($metadata, 'japanese.kana') : null;
            $speechText = is_string($kana) && trim($kana) !== '' ? trim($kana) : (string) $segment->text;
            $units[] = ContentDialogueAudioScriptUnit::spoken($speechText, $voiceId, $numericSpeed);
            if ($index < $segments->count() - 1) {
                $units[] = ContentDialogueAudioScriptUnit::pause(self::SEGMENT_PAUSE_SECONDS);
            }
        }

        $storagePath = ContentAudioScriptRenderAudio::storagePath($script->episode_id, $attempt, $speed);
        $result = $this->assembler->assemble(
            $units,
            (string) config('content_audio.disk'),
            $storagePath,
            'learning-os-content-audio-script',
            'Script audio',
        );
        if ($result->storagePath !== $storagePath) {
            throw new RuntimeException('Script audio assembler returned an unexpected storage path.');
        }

        return new ContentAudioScriptRenderResult(
            $speed,
            $numericSpeed,
            $result->storagePath,
            $result->durationSeconds,
            $result->timingData,
        );
    }
}
