<?php

namespace App\Support\Audio;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AudioStreamResponse
{
    private const CHUNK_BYTES = 64 * 1024;

    /** @param array<string, string> $headers */
    public function make(
        Request $request,
        FilesystemAdapter $disk,
        string $path,
        string $filename,
        array $headers = [],
    ): StreamedResponse {
        $size = $disk->size($path);
        $rangeHeader = $request->header('Range');
        $range = is_string($rangeHeader) ? $this->parseRange(trim($rangeHeader), $size) : null;
        if ($rangeHeader !== null && $range === null) {
            return new StreamedResponse(null, 416, [
                ...$headers,
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes */{$size}",
                'Content-Type' => 'audio/mpeg',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $start = $range['start'] ?? 0;
        $end = $range['end'] ?? max(0, $size - 1);
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = $range === null ? 200 : 206;
        $responseHeaders = [
            ...$headers,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($filename).'"',
            'Content-Length' => (string) $length,
            'Content-Type' => 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
            ...($range === null ? [] : ['Content-Range' => "bytes {$start}-{$end}/{$size}"]),
        ];

        return new StreamedResponse(function () use ($disk, $length, $path, $start): void {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Audio stream could not be opened.');
            }

            try {
                $this->advance($stream, $start);
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $bytes = fread($stream, min(self::CHUNK_BYTES, $remaining));
                    if ($bytes === false) {
                        throw new RuntimeException('Audio stream could not be read.');
                    }
                    if ($bytes === '') {
                        break;
                    }
                    echo $bytes;
                    if (PHP_SAPI !== 'cli') {
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                    $remaining -= strlen($bytes);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $responseHeaders);
    }

    /** @return null|array{start: int, end: int} */
    private function parseRange(string $header, int $size): ?array
    {
        if ($size < 1 || strlen($header) > 100
            || preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }

        if ($matches[1] === '') {
            $suffix = (int) $matches[2];
            if ($suffix < 1) {
                return null;
            }

            return ['start' => max(0, $size - $suffix), 'end' => $size - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        if ($start >= $size || $end < $start) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @param resource $stream */
    private function advance($stream, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }
        $metadata = stream_get_meta_data($stream);
        if (($metadata['seekable'] ?? false) === true && fseek($stream, $bytes) === 0) {
            return;
        }

        $remaining = $bytes;
        while ($remaining > 0 && ! feof($stream)) {
            $discarded = fread($stream, min(self::CHUNK_BYTES, $remaining));
            if ($discarded === false || $discarded === '') {
                throw new RuntimeException('Audio stream could not be positioned.');
            }
            $remaining -= strlen($discarded);
        }
        if ($remaining !== 0) {
            throw new RuntimeException('Audio stream could not be positioned.');
        }
    }

    private function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'audio.mp3';
    }
}
