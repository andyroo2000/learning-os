<?php

namespace App\Domain\Study\Support;

final class DailyAudioPracticeGeneration
{
    public const DEFAULT_TARGET_DURATION_MINUTES = 30;

    public const MIN_TARGET_DURATION_MINUTES = 5;

    public const MAX_TARGET_DURATION_MINUTES = 60;

    public const NO_ELIGIBLE_CARDS_MESSAGE = 'Daily Audio Practice needs at least one eligible study card.';

    public const FAILED_MESSAGE = 'Daily Audio Practice generation failed. Please try again in a moment.';

    public const QUEUE_FAILED_MESSAGE = 'Daily Audio Practice could not be queued. Please try again.';

    public const TRACKS = [
        ['mode' => 'drill', 'title' => 'Drills', 'sortOrder' => 0],
        ['mode' => 'dialogue', 'title' => 'Dialogues', 'sortOrder' => 1],
        ['mode' => 'story', 'title' => 'Story', 'sortOrder' => 2],
    ];

    public const SKIPPED_TRACK_METADATA = [
        'reason' => 'Disabled during drill development.',
    ];

    public static function audioUrl(string $practiceId, string $trackId): string
    {
        return "/api/daily-audio-practice/{$practiceId}/tracks/{$trackId}/audio";
    }

    public static function storagePath(string $practiceId, string $trackId): string
    {
        return "daily-audio/{$practiceId}/{$trackId}.mp3";
    }
}
