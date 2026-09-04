<?php

namespace App\Support\Audio;

use Illuminate\Filesystem\FilesystemAdapter;

final readonly class AudioStreamSource
{
    /** @param array<string, string> $headers */
    public function __construct(
        public FilesystemAdapter $disk,
        public string $path,
        public string $filename,
        public array $headers,
    ) {}

    public function size(): int
    {
        return $this->disk->size($this->path);
    }

    public function safeFilename(): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $this->filename) ?: 'audio.mp3';
    }
}
