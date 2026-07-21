<?php

namespace App\Support\Images;

interface ImageGenerator
{
    /** Return encoded WebP image bytes. */
    public function generate(string $imagePrompt): string;
}
