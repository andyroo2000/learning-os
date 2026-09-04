<?php

namespace App\Support\Audio;

final readonly class AudioStreamWindow
{
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
    ) {}
}
