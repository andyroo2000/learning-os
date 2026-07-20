<?php

namespace App\Domain\Media\Support;

final class StaticMediaPath
{
    public const MAX_TOOL_AUDIO_PATHS = 60;

    public const MAX_TOOL_AUDIO_PATH_LENGTH = 300;

    private const AVATAR_PATTERN = '#^(?:voices/)?[a-z]{2}-[a-z0-9-]+\.jpg\z#';

    private const TOOL_AUDIO_PATTERN = '#^/tools-audio/[A-Za-z0-9/_-]+\.mp3\z#';

    public static function isAvatar(string $path): bool
    {
        return preg_match(self::AVATAR_PATTERN, $path) === 1;
    }

    public static function normalizeToolAudio(string $value): string
    {
        $trimmed = trim($value);
        $parts = parse_url($trimmed);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment'])) {
            return $trimmed;
        }

        return $parts['path'] ?? $trimmed;
    }

    public static function isToolAudio(string $path): bool
    {
        return strlen($path) <= self::MAX_TOOL_AUDIO_PATH_LENGTH
            && preg_match(self::TOOL_AUDIO_PATTERN, $path) === 1;
    }
}
