<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseAudio;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentCourseRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadContentCourseAudioController extends Controller
{
    public function __invoke(
        ShowContentCourseRequest $request,
        string $courseId,
    ): StreamedResponse {
        $course = ContentCourse::query()
            ->whereKey(ContentCourseId::normalize($courseId))
            ->where('user_id', $request->user()->getKey())
            ->where('convolab_user_id', ConvoLabUserId::normalize($request->convoLabUserId()))
            ->whereNotNull('audio_storage_path')
            ->first();
        if ($course === null || ! ContentCourseAudio::ownsPath($course->id, $course->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        if (! $disk->exists($course->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response(
            $course->audio_storage_path,
            "course-{$course->id}.mp3",
            ['Content-Type' => 'audio/mpeg'],
        );
    }
}
