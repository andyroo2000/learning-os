<?php

namespace App\Support\Audio;

use JsonException;

final class FishAudioTimestampStream
{
    public static function decode(string $stream): array
    {
        $audio = '';
        $chunks = [];
        foreach (preg_split('/\r?\n/', $stream) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $event = self::event(trim(substr($line, 5)));
            $bytes = base64_decode($event['audio_base64'] ?? '', true);
            if ($bytes === false || isset($event['error'])) {
                throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
            }
            $audio .= $bytes;
            if (isset($event['alignment'])) {
                // Snapshots are cumulative: a newer snapshot replaces this chunk's old one.
                self::validateChunk($event);
                $chunks[$event['chunk_seq']] = $event;
            }
        }
        ksort($chunks, SORT_NUMERIC);

        return ['audio' => $audio, 'segments' => self::segments($chunks)];
    }

    private static function event(string $json): array
    {
        try {
            $event = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
        if (! is_array($event)) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }

        return $event;
    }

    private static function segments(array $chunks): array
    {
        $segments = [];
        foreach ($chunks as $chunk) {
            $offset = $chunk['chunk_audio_offset_sec'] ?? 0;
            foreach ($chunk['alignment']['segments'] ?? [] as $segment) {
                self::validateSegment($segment);
                $start = $segment['start'] + $offset;
                $end = $segment['end'] + $offset;
                $segments[] = ['text' => $segment['text'], 'start' => $start, 'end' => $end];
            }
        }

        return $segments;
    }

    private static function validateChunk(array $event): void
    {
        if (! is_int($event['chunk_seq'] ?? null) || $event['chunk_seq'] < 0) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
        self::validateTime($event['chunk_audio_offset_sec'] ?? null);
        if (! is_array($event['alignment']['segments'] ?? null)) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
    }

    private static function validateSegment(mixed $segment): void
    {
        if (! is_array($segment) || ! is_string($segment['text'] ?? null)) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
        self::validateTime($segment['start'] ?? null);
        self::validateTime($segment['end'] ?? null);
        if ($segment['end'] < $segment['start']) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
    }

    private static function validateTime(mixed $value): void
    {
        if (! is_int($value) && ! is_float($value)) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
        if (! is_finite($value) || $value < 0) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }
    }
}
