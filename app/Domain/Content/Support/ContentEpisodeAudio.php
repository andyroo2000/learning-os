<?php

namespace App\Domain\Content\Support;

final class ContentEpisodeAudio
{
    public const TRACK_DEFAULT = 'default';

    public const TRACK_SLOW = '0.7';

    public const TRACK_MEDIUM = '0.85';

    public const TRACK_NORMAL = '1.0';

    /** @return list<string> */
    public static function tracks(): array
    {
        return [self::TRACK_DEFAULT, self::TRACK_SLOW, self::TRACK_MEDIUM, self::TRACK_NORMAL];
    }

    public static function audioUrl(string $episodeId, string $track): string
    {
        return "/api/convolab/episodes/{$episodeId}/audio/{$track}";
    }

    public static function storagePath(string $episodeId, int $attempt, string $track): string
    {
        $slug = str_replace('.', '-', $track);

        return "content-episodes/{$episodeId}/audio-{$attempt}-{$slug}.mp3";
    }

    public static function ownsPath(string $episodeId, ?string $path): bool
    {
        return is_string($path) && preg_match(
            '/^content-episodes\/'.preg_quote($episodeId, '/').'\/audio-[1-9][0-9]*-(?:default|0-7|0-85|1-0)\.mp3$/',
            $path,
        ) === 1;
    }
}
