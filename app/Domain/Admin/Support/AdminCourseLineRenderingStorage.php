<?php

namespace App\Domain\Admin\Support;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class AdminCourseLineRenderingStorage
{
    /**
     * @param  list<string>  $courseIds
     * @return list<string>
     */
    public function ownedPathsForCourses(array $courseIds): array
    {
        return AdminCourseLineRendering::query()
            ->whereIn('course_id', $courseIds)
            ->get()
            ->map(fn (AdminCourseLineRendering $rendering): ?string => $this->ownedPath($rendering))
            ->filter()
            ->values()
            ->all();
    }

    public function ownedPath(AdminCourseLineRendering $rendering): ?string
    {
        return AdminCourseLineAudio::ownsPath(
            $rendering->course_id,
            $rendering->id,
            $rendering->audio_storage_path,
        ) ? $rendering->audio_storage_path : null;
    }

    /** @param list<string> $paths */
    public function deletePaths(array $paths): void
    {
        try {
            $disk = Storage::disk((string) config('content_courses.audio_disk'));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        foreach ($paths as $path) {
            try {
                $disk->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
