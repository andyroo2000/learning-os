<?php

namespace App\Domain\Readings\Support;

use App\Domain\Readings\Models\Reading;

final class ReadingNarrator
{
    public const DEFAULT_ID = 'fishaudio:9bf6608381a34f47ada3753f2e9f9381';

    public static function voice(Reading $reading): string
    {
        return $reading->narrator_voice_id ?? self::DEFAULT_ID;
    }

    public static function resolve(string $value): string
    {
        $value = trim($value);
        $id = array_search($value, config('reading_voices'), true);

        return $id === false ? strtolower($value) : $id;
    }

    public static function name(string $id): string
    {
        return config('reading_voices')[$id] ?? $id;
    }
}
