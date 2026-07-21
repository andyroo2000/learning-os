<?php

namespace App\Domain\Content\Support;

final class ContentAudioScriptRenderAudio
{
    public const SPEEDS = [
        ['speed' => '0.75', 'numericSpeed' => 0.75],
        ['speed' => '0.85', 'numericSpeed' => 0.85],
        ['speed' => '1.0', 'numericSpeed' => 1.0],
    ];

    public static function audioUrl(string $episodeId, string $renderId): string
    {
        return "/api/convolab/scripts/{$episodeId}/audio/{$renderId}";
    }

    public static function storagePath(string $episodeId, int $attempt, string $speed): string
    {
        $slug = str_replace('.', '-', $speed);

        return "content-audio-scripts/{$episodeId}/render-{$attempt}-{$slug}.mp3";
    }

    public static function ownsPath(string $episodeId, ?string $path): bool
    {
        return is_string($path) && preg_match(
            '/^content-audio-scripts\/'.preg_quote($episodeId, '/').'\/render-[1-9][0-9]*-(?:0-75|0-85|1-0)\.mp3$/',
            $path,
        ) === 1;
    }
}
