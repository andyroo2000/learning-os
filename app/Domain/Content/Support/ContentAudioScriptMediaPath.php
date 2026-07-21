<?php

namespace App\Domain\Content\Support;

final class ContentAudioScriptMediaPath
{
    public static function isSafe(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        $normalized = ltrim($path, '/');

        return $normalized === $path
            && str_starts_with($normalized, 'study-media/')
            && ! in_array('..', explode('/', $normalized), true)
            && ! in_array('.', explode('/', $normalized), true);
    }
}
