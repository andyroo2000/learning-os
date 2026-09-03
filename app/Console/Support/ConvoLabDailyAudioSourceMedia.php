<?php

namespace App\Console\Support;

final readonly class ConvoLabDailyAudioSourceMedia
{
    public function __construct(
        public string $root,
        public string $bucket,
    ) {}
}
