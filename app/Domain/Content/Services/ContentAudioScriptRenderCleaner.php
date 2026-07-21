<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ContentAudioScriptRenderCleaner
{
    /** @param list<string|null> $paths */
    public function deleteFiles(string $episodeId, array $paths): void
    {
        $disk = Storage::disk((string) config('content_audio.disk'));
        foreach (array_unique($paths) as $path) {
            if (! ContentAudioScriptRenderAudio::ownsPath($episodeId, $path)) {
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
