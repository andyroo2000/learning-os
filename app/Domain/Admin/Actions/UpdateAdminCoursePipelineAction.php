<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\UpdateAdminCoursePipelineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Support\LegacyJavaScriptValue;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;

final class UpdateAdminCoursePipelineAction
{
    public function handle(string $courseId, UpdateAdminCoursePipelineData $data): void
    {
        $courseId = ContentCourseId::normalize($courseId);

        DB::transaction(function () use ($courseId, $data): void {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->lockForUpdate()
                ->first();
            if (! $course instanceof ContentCourse) {
                throw AdminMutationException::courseNotFound();
            }

            $existing = is_array($course->script_json) ? $course->script_json : [];
            $course->script_json = $data->stage === 'exchanges'
                ? [
                    '_pipelineStage' => 'exchanges',
                    '_exchanges' => $data->data,
                ]
                : [
                    '_pipelineStage' => 'script',
                    '_exchanges' => LegacyJavaScriptValue::isTruthy($existing['_exchanges'] ?? null)
                        ? $existing['_exchanges']
                        : [],
                    '_scriptUnits' => $data->data,
                ];
            $course->audio_url = null;
            $course->timing_data = null;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->save();
        });
    }
}
