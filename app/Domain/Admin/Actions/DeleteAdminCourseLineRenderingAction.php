<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineAudio;
use App\Domain\Content\Support\ContentCourseId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class DeleteAdminCourseLineRenderingAction
{
    public function handle(string $courseId, string $renderingId): void
    {
        $courseId = ContentCourseId::normalize($courseId);
        $renderingId = strtolower(trim($renderingId));
        if (! Str::isUuid($renderingId)) {
            throw AdminMutationException::courseLineRenderingNotFound();
        }
        $rendering = DB::transaction(function () use ($courseId, $renderingId): AdminCourseLineRendering {
            $rendering = AdminCourseLineRendering::query()
                ->whereKey($renderingId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();
            if (! $rendering instanceof AdminCourseLineRendering) {
                throw AdminMutationException::courseLineRenderingNotFound();
            }

            $rendering->delete();

            return $rendering;
        });

        if (! AdminCourseLineAudio::ownsPath(
            $rendering->course_id,
            $rendering->id,
            $rendering->audio_storage_path,
        )) {
            return;
        }

        try {
            Storage::disk((string) config('content_courses.audio_disk'))
                ->delete($rendering->audio_storage_path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
