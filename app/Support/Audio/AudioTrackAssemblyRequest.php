<?php

namespace App\Support\Audio;

final readonly class AudioTrackAssemblyRequest
{
    public function __construct(
        public string $disk,
        public string $storagePath,
        public string $temporaryPrefix,
        public string $label,
    ) {}
}
