<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Results\ContentDialogueAudioScriptUnit;
use App\Domain\Content\Results\ContentEpisodeAudioTrackResult;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\FishAudioVoiceNormalizer;
use InvalidArgumentException;
use RuntimeException;

final readonly class ContentEpisodeAudioAssembler
{
    public function __construct(private AudioTrackAssembler $assembler) {}

    /** @param list<ContentSentence> $sentences */
    public function assemble(
        string $episodeId,
        int $attempt,
        string $track,
        float $speed,
        array $sentences,
        bool $pauseMode = false,
    ): ContentEpisodeAudioTrackResult {
        $units = [];
        $sentenceIndexes = [];
        foreach ($sentences as $index => $sentence) {
            $voiceId = FishAudioVoiceNormalizer::normalize((string) $sentence->speaker?->voice_id);
            if ($voiceId === null) {
                throw new InvalidArgumentException('Dialogue contains an unsupported speech voice ID.');
            }
            $sentenceIndexes[count($units)] = $sentence->id;
            $units[] = ContentDialogueAudioScriptUnit::spoken($this->speechText($sentence), $voiceId, $speed);
            if ($index < count($sentences) - 1) {
                $units[] = ContentDialogueAudioScriptUnit::pause($pauseMode ? 1.5 : 1.0);
            }
        }

        $storagePath = ContentEpisodeAudio::storagePath($episodeId, $attempt, $track);
        $result = $this->assembler->assemble(
            $units,
            (string) config('content_audio.disk'),
            $storagePath,
            'learning-os-content-episode',
            'Episode audio',
        );
        if ($result->storagePath !== $storagePath) {
            throw new RuntimeException('Episode audio assembler returned an unexpected storage path.');
        }

        $timings = [];
        foreach ($result->timingData as $timing) {
            $sentenceId = $sentenceIndexes[$timing['unitIndex']] ?? null;
            if ($sentenceId !== null) {
                $timings[$sentenceId] = [
                    'startTime' => $timing['startTime'],
                    'endTime' => $timing['endTime'],
                ];
            }
        }

        return new ContentEpisodeAudioTrackResult(
            $track,
            $result->storagePath,
            $result->durationSeconds,
            $timings,
        );
    }

    private function speechText(ContentSentence $sentence): string
    {
        $metadata = $sentence->metadata;
        $kana = is_array($metadata) ? ($metadata['japanese']['kana'] ?? null) : null;

        return is_string($kana) && trim($kana) !== '' ? trim($kana) : (string) $sentence->text;
    }
}
