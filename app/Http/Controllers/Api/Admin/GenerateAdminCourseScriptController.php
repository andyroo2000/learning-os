<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GenerateAdminCourseScriptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class GenerateAdminCourseScriptController extends Controller
{
    public function __invoke(
        ConvoLabAdminWriteRequest $request,
        string $courseId,
        GenerateAdminCourseScriptAction $action,
    ): JsonResponse {
        return response()->json($action->handle($courseId));
    }
}
