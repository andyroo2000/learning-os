<?php

namespace App\Domain\Readings\Actions;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StoreReadingImage
{
    public function handle(string $readingId, string $file): string
    {
        $path = $this->pathFor($readingId, $file);
        $bytes = file_get_contents($file);
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('Could not save the illustration.');
        }

        return $path;
    }

    public function pathFor(string $readingId, string $file): string
    {
        $mime = mime_content_type($file);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true) || filesize($file) > 10_000_000) {
            throw new RuntimeException('Illustration must be a JPEG or PNG under 10 MB.');
        }
        $extension = $mime === 'image/png' ? 'png' : 'jpg';

        return 'readings/'.$readingId.'/'.hash_file('sha256', $file).'.'.$extension;
    }
}
