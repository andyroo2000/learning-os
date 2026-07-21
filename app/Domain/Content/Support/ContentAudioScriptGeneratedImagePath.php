<?php

namespace App\Domain\Content\Support;

final class ContentAudioScriptGeneratedImagePath
{
    public static function storagePath(string $episodeId, string $segmentId, int $attempt, string $mediaId): string
    {
        return "study-media/audio-scripts/{$episodeId}/{$segmentId}-{$attempt}-{$mediaId}.webp";
    }

    public static function ownsPath(string $episodeId, string $segmentId, ?string $path): bool
    {
        return is_string($path) && preg_match(
            '/^study-media\/audio-scripts\/'.preg_quote($episodeId, '/').'\/'
                .preg_quote($segmentId, '/').'-[1-9][0-9]*-'
                .'[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.webp$/',
            $path,
        ) === 1;
    }
}
