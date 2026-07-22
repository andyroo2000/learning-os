<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineAudio;
use App\Domain\Content\Support\ContentCourseId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadAdminCourseLineRenderingController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        string $renderingId,
    ): StreamedResponse {
        $courseId = ContentCourseId::normalize($courseId);
        $rendering = AdminCourseLineRendering::query()
            ->whereKey(strtolower($renderingId))
            ->where('course_id', $courseId)
            ->first();
        if (! $rendering instanceof AdminCourseLineRendering
            || ! AdminCourseLineAudio::ownsPath(
                $courseId,
                $rendering->id,
                $rendering->audio_storage_path,
            )) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        if (! $disk->exists($rendering->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response(
            $rendering->audio_storage_path,
            "line-{$rendering->unit_index}.mp3",
            ['Content-Type' => 'audio/mpeg'],
        );
    }
}
