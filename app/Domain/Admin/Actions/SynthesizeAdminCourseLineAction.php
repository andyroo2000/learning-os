<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\SynthesizeAdminCourseLineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineAudio;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class SynthesizeAdminCourseLineAction
{
    public function __construct(private AudioSpeechGenerator $speechGenerator) {}

    public function handle(string $courseId, SynthesizeAdminCourseLineData $data): AdminCourseLineRendering
    {
        $courseId = ContentCourseId::normalize($courseId);
        if (! ContentCourse::query()->whereKey($courseId)->exists()) {
            throw AdminMutationException::courseNotFound();
        }

        try {
            $bytes = $this->speechGenerator->generate($data->text, $data->voiceId, $data->speed);
        } catch (AudioSpeechGenerationException $exception) {
            throw AdminMutationException::courseLineSynthesisUnavailable($exception);
        }

        $renderingId = (string) Str::uuid();
        $path = AdminCourseLineAudio::storagePath($courseId, $renderingId);
        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        $stored = false;

        try {
            if (! $disk->put($path, $bytes)) {
                throw AdminMutationException::courseLineSynthesisUnavailable();
            }
            $stored = true;

            return DB::transaction(function () use (
                $courseId,
                $data,
                $renderingId,
                $path,
            ): AdminCourseLineRendering {
                $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
                if (! $course instanceof ContentCourse) {
                    throw AdminMutationException::courseNotFound();
                }

                return AdminCourseLineRendering::query()->forceCreate([
                    'id' => $renderingId,
                    'course_id' => $course->id,
                    'unit_index' => $data->unitIndex,
                    'text' => $data->text,
                    'speed' => $data->speed,
                    'voice_id' => $data->voiceId,
                    'audio_url' => AdminCourseLineAudio::audioUrl($course->id, $renderingId),
                    'audio_storage_path' => $path,
                    'created_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    $disk->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}
