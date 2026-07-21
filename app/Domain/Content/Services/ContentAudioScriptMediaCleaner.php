<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Support\ContentAudioScriptMediaPath;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ContentAudioScriptMediaCleaner
{
    /** @param list<string|null> $paths */
    public function deleteFiles(array $paths): void
    {
        $disk = Storage::disk('media');

        foreach (array_unique($paths) as $path) {
            if (! ContentAudioScriptMediaPath::isSafe($path)) {
                continue;
            }

            try {
                $disk->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
