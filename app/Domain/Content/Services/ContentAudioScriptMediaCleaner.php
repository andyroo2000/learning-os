<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Support\ContentAudioScriptGeneratedImagePath;
use App\Domain\Content\Support\ContentAudioScriptMediaPath;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ContentAudioScriptMediaCleaner
{
    public function deleteAttemptOrphans(string $episodeId, int $attempt): void
    {
        $disk = Storage::disk('media');
        try {
            $paths = collect($disk->files("study-media/audio-scripts/{$episodeId}"))
                ->filter(fn (string $path): bool => ContentAudioScriptGeneratedImagePath::ownsAttemptPath(
                    $episodeId,
                    $attempt,
                    $path,
                ))
                ->values();
            if ($paths->isEmpty()) {
                return;
            }

            $referenced = ContentAudioScriptMedia::query()
                ->whereIn('storage_path', $paths->all())
                ->pluck('storage_path')
                ->all();
            $this->deleteFiles($paths->diff($referenced)->all());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

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
