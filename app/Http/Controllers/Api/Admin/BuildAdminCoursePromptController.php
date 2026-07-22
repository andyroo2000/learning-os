<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\BuildAdminCoursePromptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use Illuminate\Http\JsonResponse;

final class BuildAdminCoursePromptController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        BuildAdminCoursePromptAction $action,
    ): JsonResponse {
        return response()->json($action->handle($courseId));
    }
}
