<?php

namespace App\Domain\Journal\Support;

use Illuminate\Support\Facades\Storage;
use JsonException;

final class JournalTimedAudioCache
{
    public static function read(string $path): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $data = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (self::valid($data)) {
                return $data;
            }
        } catch (JsonException) {
            // A failed filesystem write must not make every subsequent retry fail.
        }
        $disk->delete($path);

        return null;
    }

    private static function valid(mixed $data): bool
    {
        if (! is_array($data) || ! is_string($data['audioBase64'] ?? null)) {
            return false;
        }
        if (! is_array($data['sentences'] ?? null) || $data['sentences'] === []) {
            return false;
        }

        return base64_decode($data['audioBase64'], true) !== false;
    }
}
