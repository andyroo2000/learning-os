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

        foreach ($data['sentences'] as $sentence) {
            if (! self::validSentence($sentence)) {
                return false;
            }
        }

        return $data['audioBase64'] !== '' && base64_decode($data['audioBase64'], true) !== false;
    }

    private static function validSentence(mixed $sentence): bool
    {
        if (! is_array($sentence) || ! is_string($sentence['text'] ?? null)) {
            return false;
        }
        if (! self::validTime($sentence['start'] ?? null) || ! self::validTime($sentence['end'] ?? null)) {
            return false;
        }

        return $sentence['end'] >= $sentence['start'];
    }

    private static function validTime(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        return is_finite($value) && $value >= 0;
    }
}
