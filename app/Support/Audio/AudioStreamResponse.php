<?php

namespace App\Support\Audio;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AudioStreamResponse
{
    private const CHUNK_BYTES = 64 * 1024;

    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "sandbox; default-src 'none'",
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /** @param array<string, string> $headers */
    public function make(
        Request $request,
        FilesystemAdapter $disk,
        string $path,
        string $filename,
        array $headers = [],
    ): StreamedResponse {
        $source = new AudioStreamSource($disk, $path, $filename, $headers);
        $size = $source->size();
        $rangeHeader = $request->header('Range');
        $range = is_string($rangeHeader)
            ? (new AudioStreamRangeParser(trim($rangeHeader), $size))->parse()
            : null;
        if ($rangeHeader !== null && $range === null) {
            return $this->rangeNotSatisfiable($size, $source);
        }

        $window = $this->streamWindow($range, $size);
        $status = $range === null ? 200 : 206;
        $responseHeaders = [
            ...$source->headers,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$source->safeFilename().'"',
            'Content-Length' => (string) $window->length,
            'Content-Type' => 'audio/mpeg',
            ...self::SECURITY_HEADERS,
            ...($range === null ? [] : ['Content-Range' => "bytes {$window->start}-{$window->end}/{$size}"]),
        ];

        return new StreamedResponse(
            $this->streamCallback($source, $window),
            $status,
            $responseHeaders,
        );
    }

    private function rangeNotSatisfiable(int $size, AudioStreamSource $source): StreamedResponse
    {
        return new StreamedResponse(null, 416, [
            ...$source->headers,
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes */{$size}",
            'Content-Type' => 'audio/mpeg',
            ...self::SECURITY_HEADERS,
        ]);
    }

    private function streamCallback(
        AudioStreamSource $source,
        AudioStreamWindow $window,
    ): Closure {
        return function () use ($source, $window): void {
            $stream = $source->disk->readStream($source->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Audio stream could not be opened.');
            }

            try {
                $this->streamContents($stream, $window);
            } finally {
                fclose($stream);
            }
        };
    }

    /** @param resource $stream */
    private function streamContents($stream, AudioStreamWindow $window): void
    {
        $this->advance($stream, $window);
        $remaining = $window->length;
        while ($remaining > 0 && ! feof($stream)) {
            $bytes = fread($stream, min(self::CHUNK_BYTES, $remaining));
            if ($bytes === false) {
                throw new RuntimeException('Audio stream could not be read.');
            }
            if ($bytes === '') {
                break;
            }
            echo $bytes;
            $this->flushOutput();
            $remaining -= strlen($bytes);
        }
    }

    /** @param null|array{start: int, end: int} $range */
    private function streamWindow(?array $range, int $size): AudioStreamWindow
    {
        $start = $range['start'] ?? 0;
        $end = $range['end'] ?? max(0, $size - 1);
        $length = $size === 0 ? 0 : $end - $start + 1;

        return new AudioStreamWindow($start, $end, $length);
    }

    private function flushOutput(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /** @param resource $stream */
    private function advance($stream, AudioStreamWindow $window): void
    {
        if ($window->start === 0) {
            return;
        }
        if ($this->seek($stream, $window)) {
            return;
        }

        if ($this->discardBytes($stream, $window) !== 0) {
            throw new RuntimeException('Audio stream could not be positioned.');
        }
    }

    /** @param resource $stream */
    private function seek($stream, AudioStreamWindow $window): bool
    {
        $metadata = stream_get_meta_data($stream);

        return ($metadata['seekable'] ?? false) === true && fseek($stream, $window->start) === 0;
    }

    /** @param resource $stream */
    private function discardBytes($stream, AudioStreamWindow $window): int
    {
        $remaining = $window->start;
        while ($remaining > 0 && ! feof($stream)) {
            $discarded = fread($stream, min(self::CHUNK_BYTES, $remaining));
            if ($discarded === false || $discarded === '') {
                throw new RuntimeException('Audio stream could not be positioned.');
            }
            $remaining -= strlen($discarded);
        }

        return $remaining;
    }
}
