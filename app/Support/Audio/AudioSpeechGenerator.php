<?php

namespace App\Support\Audio;

interface AudioSpeechGenerator
{
    public function generate(string $text, string $voiceId, float $speed = 1.0): string;
}
