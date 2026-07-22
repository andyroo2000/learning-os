<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GenerateAdminCourseAudioAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class GenerateAdminCourseAudioController extends Controller
{
    public function __invoke(
        ConvoLabAdminWriteRequest $request,
        string $courseId,
        GenerateAdminCourseAudioAction $action,
    ): JsonResponse {
        return response()->json($action->handle($courseId));
    }
}
